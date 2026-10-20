package main

import (
	"encoding/json"
	"encoding/pem"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"github.com/pelican/wings/ibyte/restic/engine"
	"gopkg.in/yaml.v3"
)

func helperFixture(t *testing.T, server *httptest.Server) (string, string) {
	t.Helper()
	binary := os.Getenv("RESTIC_TEST_BINARY")
	if binary == "" {
		t.Skip("real Restic binary required")
	}
	root := t.TempDir()
	password := filepath.Join(root, "password")
	os.WriteFile(password, []byte("disposable-test-password"), 0600)
	settings := engine.Config{Binary: binary, State: filepath.Join(root, "state"), Node: 1, MaxArchiveBytes: 1024, Profiles: map[string]engine.Profile{"default": {Backend: "local", Root: filepath.Join(root, "repos"), PasswordFile: password}}}
	raw, _ := json.Marshal(settings)
	addon := filepath.Join(root, "addon.json")
	os.WriteFile(addon, raw, 0600)
	host, port, _ := net.SplitHostPort(strings.TrimPrefix(strings.TrimPrefix(server.URL, "http://"), "https://"))
	var cfg wingsConfig
	cfg.UUID = "node"
	cfg.Token = "disposable-token"
	cfg.TokenID = "test"
	cfg.API.Host = host
	cfg.API.Port, _ = strconv.Atoi(port)
	cfg.System.Data = filepath.Join(root, "data")
	os.Mkdir(cfg.System.Data, 0700)
	if server.TLS != nil {
		cfg.API.SSL.Enabled = true
		cfg.API.SSL.Cert = filepath.Join(root, "cert.pem")
		os.WriteFile(cfg.API.SSL.Cert, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw}), 0600)
	}
	raw, _ = yaml.Marshal(cfg)
	path := filepath.Join(root, "config.yml")
	os.WriteFile(path, raw, 0600)
	return path, addon
}
func TestActualHelperHealthUsesBearerAndNativeResponseShape(t *testing.T) {
	hits := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer disposable-token" || r.URL.RawQuery != "" {
			t.Error("authentication leaked or missing")
			w.WriteHeader(403)
			return
		}
		hits++
		switch r.URL.Path {
		case "/api/system":
			w.Write([]byte(`{"version":"1.0.0-rc.1+ibyte.3.0.0-preview.2"}`))
		case "/api/servers":
			w.Write([]byte(`[{"configuration":{"uuid":"11111111-1111-4111-8111-111111111111"}}]`))
		case "/api/servers/11111111-1111-4111-8111-111111111111/restic/readiness":
			w.Write([]byte(`{"ready":true,"version":"3.0.0-preview.2"}`))
		default:
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	cfg, addon := helperFixture(t, server)
	if err := runArgs([]string{"--mode", "preflight", "--config", cfg, "--addon", addon}); err != nil {
		t.Fatal(err)
	}
	if err := runArgs([]string{"--mode", "health", "--config", cfg, "--addon", addon}); err != nil {
		t.Fatal(err)
	}
	if hits != 3 {
		t.Fatalf("expected three authenticated read-only calls, got %d", hits)
	}
}
func TestHelperRejectsWrongVersion(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{"version":"wrong"}`)) }))
	defer server.Close()
	cfg, addon := helperFixture(t, server)
	if err := runArgs([]string{"--mode", "health", "--config", cfg, "--addon", addon}); err == nil {
		t.Fatal("wrong daemon accepted")
	}
}

func TestHelperHealthOnEmptyNodeUsesAuthenticatedAdminReadiness(t *testing.T) {
	hits := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer disposable-token" {
			t.Error("missing daemon auth")
			w.WriteHeader(403)
			return
		}
		switch r.URL.Path {
		case "/api/system":
			w.Write([]byte(`{"version":"1.0.0-rc.1+ibyte.3.0.0-preview.2"}`))
		case "/api/servers":
			w.Write([]byte(`[]`))
		case "/api/restic/readiness":
			hits++
			w.Write([]byte(`{"ready":true,"version":"3.0.0-preview.2"}`))
		default:
			t.Error("unexpected path", r.URL.Path)
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	cfg, addon := helperFixture(t, server)
	if err := runArgs([]string{"--mode", "health", "--config", cfg, "--addon", addon}); err != nil {
		t.Fatal(err)
	}
	if hits != 1 {
		t.Fatal("empty node readiness was not checked")
	}
}
func TestHelperTLSFailsClosed(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("untrusted certificate must fail before HTTP") }))
	defer server.Close()
	cfg, addon := helperFixture(t, server)
	if err := runArgs([]string{"--mode", "health", "--config", cfg, "--addon", addon}); err == nil {
		t.Fatal("untrusted TLS accepted")
	}
}
func TestHelperDoesNotFollowRedirects(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "https://example.invalid/stolen", 302)
	}))
	defer server.Close()
	cfg, addon := helperFixture(t, server)
	if err := runArgs([]string{"--mode", "health", "--config", cfg, "--addon", addon}); err == nil {
		t.Fatal("redirect accepted")
	}
}
