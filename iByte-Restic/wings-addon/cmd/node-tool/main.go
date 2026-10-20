// node-tool performs read-only config/health checks for the unattended installer.
package main

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/pelican/wings/ibyte/restic/engine"
	"gopkg.in/yaml.v3"
)

type wingsConfig struct {
	UUID    string `yaml:"uuid"`
	Token   string `yaml:"token"`
	TokenID string `yaml:"token_id"`
	API     struct {
		Host string `yaml:"host"`
		Port int    `yaml:"port"`
		SSL  struct {
			Enabled bool   `yaml:"enabled"`
			Cert    string `yaml:"cert"`
		} `yaml:"ssl"`
	} `yaml:"api"`
	System struct {
		Data string `yaml:"data"`
	} `yaml:"system"`
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "preflight/health failed:", err)
		os.Exit(1)
	}
}
func run() error {
	return runArgs(os.Args[1:])
}
func runArgs(args []string) error {
	flags := flag.NewFlagSet("ibyte-node-tool", flag.ContinueOnError)
	path := flags.String("config", "/etc/pelican/config.yml", "existing Wings config")
	addon := flags.String("addon", "/etc/pelican/ibyte-restic.json", "addon profile")
	mode := flags.String("mode", "preflight", "preflight, data-root, configure, init-repo or health")
	hostID := flags.Int("host", 0, "backup host ID for explicit fresh repo initialization")
	serverID := flags.String("server", "", "server UUID for fresh repo initialization")
	profile := flags.String("profile", "", "approved profile")
	yes := flags.Bool("yes", false, "confirm fresh repository initialization")
	nodeID := flags.Int("node-id", 0, "numeric Panel node ID for unattended local profile setup")
	localRoot := flags.String("local-root", "/var/lib/ibyte-restic/repositories", "approved local repository base")
	if err := flags.Parse(args); err != nil {
		return err
	}
	raw, err := os.ReadFile(*path)
	if err != nil {
		return errors.New("Wings config unavailable")
	}
	var c wingsConfig
	if err = yaml.Unmarshal(raw, &c); err != nil {
		return errors.New("invalid Wings YAML")
	}
	if c.Token == "" || c.TokenID == "" || c.UUID == "" || !filepath.IsAbs(c.System.Data) || c.API.Port < 1 || c.API.Port > 65535 {
		return errors.New("explicit Pelican identity, API and data root required")
	}
	if *mode == "data-root" {
		fmt.Print(c.System.Data)
		return nil
	}
	if *mode == "configure" {
		if *nodeID < 1 || !*yes {
			return errors.New("configure requires --node-id and --yes")
		}
		if _, err := os.Stat(*addon); err == nil {
			return errors.New("addon config already exists; refusing overwrite")
		}
		for _, pair := range [][2]string{{c.System.Data, *localRoot}, {*localRoot, c.System.Data}} {
			rel, err := filepath.Rel(pair[0], pair[1])
			if err != nil || rel == "." || (!strings.HasPrefix(rel, ".."+string(filepath.Separator)) && rel != "..") {
				return errors.New("repository root must be outside game data")
			}
		}
		password := filepath.Join(filepath.Dir(*path), "ibyte-restic.password")
		if _, err := os.Stat(password); os.IsNotExist(err) {
			secret := make([]byte, 32)
			if _, err = rand.Read(secret); err != nil {
				return err
			}
			f, err := os.OpenFile(password, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
			if err != nil {
				return err
			}
			_, err = f.WriteString(hex.EncodeToString(secret))
			f.Close()
			if err != nil {
				return err
			}
		}
		settings := engine.Config{Binary: "/opt/ibyte-restic/restic", State: "/var/lib/ibyte-restic/state", Node: *nodeID, MaxArchiveBytes: 10 << 30, Profiles: map[string]engine.Profile{"default": {Backend: "local", Root: *localRoot, PasswordFile: password}}}
		if err = settings.Validate(); err != nil {
			return errors.New("generated profile validation failed")
		}
		b, err := json.MarshalIndent(settings, "", "  ")
		if err != nil {
			return err
		}
		f, err := os.OpenFile(*addon, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
		if err != nil {
			return err
		}
		defer f.Close()
		if _, err = f.Write(b); err != nil {
			return err
		}
		return f.Sync()
	}
	settings, err := engine.LoadConfig(*addon)
	if err != nil {
		return errors.New("addon profiles or permissions invalid")
	}
	if *mode == "init-repo" {
		if !*yes {
			return errors.New("--yes required")
		}
		e, err := engine.New(settings)
		if err != nil {
			return err
		}
		return e.Provision(context.Background(), engine.Request{Host: *hostID, Node: settings.Node, Profile: *profile, Server: *serverID, Backup: "00000000-0000-4000-8000-000000000000"})
	}
	if *mode == "preflight" {
		fmt.Println("configuration and private credential files valid")
		return nil
	}
	if *mode != "health" {
		return errors.New("invalid mode")
	}
	// Connect using the configured certificate hostname, resolving the daemon address locally.
	// TLS verification is never bypassed, including on loopback.
	host := c.API.Host
	if host == "" || host == "0.0.0.0" || host == "::" {
		host = "127.0.0.1"
	}
	dialAddress := net.JoinHostPort(host, fmt.Sprint(c.API.Port))
	scheme := "http"
	if c.API.SSL.Enabled {
		scheme = "https"
		raw, err := os.ReadFile(c.API.SSL.Cert)
		if err != nil {
			return errors.New("TLS certificate unavailable")
		}
		block, _ := pem.Decode(raw)
		if block == nil {
			return errors.New("invalid TLS certificate")
		}
		cert, err := x509.ParseCertificate(block.Bytes)
		if err != nil || len(cert.DNSNames) == 0 {
			return errors.New("TLS certificate needs a DNS subject alternative name")
		}
		host = cert.DNSNames[0]
		if strings.Contains(host, "*") {
			return errors.New("wildcard-only certificate needs explicit hostname support; refusing unsafe TLS fallback")
		}
	}
	transport := &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}, DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		return (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, network, dialAddress)
	}}
	client := &http.Client{Timeout: 15 * time.Second, Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("redirect refused") }}
	base := scheme + "://" + net.JoinHostPort(host, fmt.Sprint(c.API.Port))
	get := func(path string, out any) error {
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		req, _ := http.NewRequestWithContext(ctx, "GET", base+path, nil)
		req.Header.Set("Authorization", "Bearer "+c.Token)
		res, err := client.Do(req)
		if err != nil {
			return errors.New("daemon unreachable or TLS rejected")
		}
		defer res.Body.Close()
		if res.StatusCode != 200 {
			return errors.New("daemon rejected health request")
		}
		return json.NewDecoder(res.Body).Decode(out)
	}
	var system struct {
		Version string `json:"version"`
	}
	if err = get("/api/system", &system); err != nil {
		return err
	}
	if !strings.Contains(system.Version, "ibyte.3.0.0-preview.2") {
		return errors.New("addon version handshake mismatch")
	}
	var servers []struct {
		Configuration struct {
			UUID string `json:"uuid"`
		} `json:"configuration"`
	}
	if err = get("/api/servers", &servers); err != nil {
		return err
	}
	var ready struct {
		Ready   bool   `json:"ready"`
		Version string `json:"version"`
	}
	readinessPath := "/api/restic/readiness"
	if len(servers) > 0 {
		readinessPath = "/api/servers/" + servers[0].Configuration.UUID + "/restic/readiness"
	}
	if err = get(readinessPath, &ready); err != nil {
		return err
	}
	if !ready.Ready || ready.Version != engine.Version {
		return errors.New("addon not ready")
	}
	fmt.Println("daemon and authenticated addon healthy")
	return nil
}
