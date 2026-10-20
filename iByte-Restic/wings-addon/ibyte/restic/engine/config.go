// Package engine provides isolated Restic execution. It has no Panel/Wings dependency.
package engine

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

var uuid = regexp.MustCompile(`^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$`)
var slug = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,63}$`)
var snapshotID = regexp.MustCompile(`^[a-f0-9]{64}$`)

type Profile struct {
	Backend         string `json:"backend"`
	Root            string `json:"root,omitempty"`
	Endpoint        string `json:"endpoint,omitempty"`
	Bucket          string `json:"bucket,omitempty"`
	Prefix          string `json:"prefix,omitempty"`
	PasswordFile    string `json:"password_file"`
	CredentialsFile string `json:"credentials_file,omitempty"`
}
type Config struct {
	Binary          string             `json:"binary"`
	State           string             `json:"state"`
	Node            int                `json:"node"`
	MaxArchiveBytes int64              `json:"max_archive_bytes"`
	Profiles        map[string]Profile `json:"profiles"`
}

func LoadConfig(path string) (Config, error) {
	var c Config
	b, err := privateFile(path)
	if err != nil {
		return c, err
	}
	err = json.Unmarshal(b, &c)
	if err != nil {
		return c, errors.New("invalid node configuration")
	}
	return c, c.Validate()
}
func (c Config) Validate() error {
	if !filepath.IsAbs(c.Binary) || !filepath.IsAbs(c.State) || c.Node < 1 || c.MaxArchiveBytes < 1 {
		return errors.New("invalid absolute paths, node identity or archive limit")
	}
	if err := noSymlink(c.Binary); err != nil {
		return err
	}
	for _, p := range []string{c.Binary, c.State} {
		if err := trustedPath(p); err != nil {
			return err
		}
	}
	st, err := os.Stat(c.Binary)
	if err != nil || !st.Mode().IsRegular() {
		return errors.New("Restic executable missing or not regular")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, c.Binary, "version")
	command.Env = []string{"SystemRoot=" + os.Getenv("SystemRoot")}
	version, err := command.Output()
	if err != nil || !strings.HasPrefix(string(version), "restic 0.19.1 ") {
		return errors.New("Restic 0.19.1 runtime required")
	}
	for name, p := range c.Profiles {
		if !slug.MatchString(name) {
			return errors.New("invalid profile name")
		}
		if _, err := privateFile(p.PasswordFile); err != nil {
			return err
		}
		switch p.Backend {
		case "local":
			if !filepath.IsAbs(p.Root) {
				return errors.New("repository root must be absolute")
			}
			if err := noSymlink(p.Root); err != nil {
				return err
			}
			if err := trustedPath(p.Root); err != nil {
				return err
			}
			rel, err := filepath.Rel(p.Root, c.State)
			if err != nil || rel == "." || (!strings.HasPrefix(rel, ".."+string(filepath.Separator)) && rel != "..") {
				return errors.New("state must be outside repository root")
			}
		case "s3":
			u, err := url.Parse(p.Endpoint)
			if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") {
				return errors.New("S3 endpoint must be an HTTPS origin")
			}
			if !regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$`).MatchString(p.Bucket) || !slug.MatchString(p.Prefix) {
				return errors.New("invalid S3 bucket/prefix")
			}
			if _, err := privateFile(p.CredentialsFile); err != nil {
				return err
			}
		default:
			return errors.New("unsupported backend")
		}
	}
	return nil
}
func privateFile(path string) ([]byte, error) {
	if !filepath.IsAbs(path) {
		return nil, errors.New("secret file must be absolute")
	}
	if err := noSymlink(path); err != nil {
		return nil, err
	}
	if err := trustedPath(path); err != nil {
		return nil, err
	}
	st, err := os.Stat(path)
	if err != nil {
		return nil, errors.New("secret file unavailable")
	}
	if !st.Mode().IsRegular() || st.Size() > 65536 || (runtime.GOOS != "windows" && st.Mode().Perm()&0077 != 0) {
		return nil, errors.New("secret file must be regular, small and mode 0600")
	}
	if err := privateOwner(st); err != nil {
		return nil, err
	}
	return os.ReadFile(path)
}
func noSymlink(path string) error {
	if !filepath.IsAbs(path) {
		return errors.New("absolute path required")
	}
	for p := filepath.Clean(path); ; p = filepath.Dir(p) {
		st, err := os.Lstat(p)
		if err != nil && !os.IsNotExist(err) {
			return err
		}
		if err == nil && st.Mode()&os.ModeSymlink != 0 {
			return errors.New("symlink path rejected")
		}
		if filepath.Dir(p) == p {
			break
		}
	}
	return nil
}
func safeTree(root string) error {
	if err := noSymlink(root); err != nil {
		return err
	}
	return filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.Type()&os.ModeSymlink != 0 {
			return errors.New("symlinks unsupported in preview")
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		if !info.IsDir() && !info.Mode().IsRegular() {
			return errors.New("special files unsupported")
		}
		return nil
	})
}
func (c Config) repository(r Request) (string, Profile, error) {
	repo, p, err := c.baseRepository(r)
	if err != nil {
		return repo, p, err
	}
	k, keyErr := c.key(r)
	if keyErr == nil && k.Prepared {
		p.PasswordFile = c.keyPasswordPath(r)
	} else if keyErr != nil && !os.IsNotExist(keyErr) {
		return "", p, keyErr
	}
	return repo, p, nil
}
func (c Config) baseRepository(r Request) (string, Profile, error) {
	p, ok := c.Profiles[r.Profile]
	if !ok || r.Generation > 1000000000 || (r.Backend != "" && r.Backend != p.Backend) || r.Host < 1 || r.Node != c.Node || !uuid.MatchString(r.Server) || !uuid.MatchString(r.Backup) {
		return "", p, errors.New("invalid identity or unapproved profile")
	}
	suffix := fmt.Sprintf("host-%d/node-%d/%s", r.Host, r.Node, r.Server)
	if r.Generation > 0 {
		suffix += fmt.Sprintf("--g%d", r.Generation)
	}
	if p.Backend == "local" {
		repo := filepath.Join(p.Root, filepath.FromSlash(suffix))
		return repo, p, noSymlink(repo)
	}
	return "s3:" + strings.TrimRight(p.Endpoint, "/") + "/" + p.Bucket + "/" + p.Prefix + "/" + suffix, p, nil
}
