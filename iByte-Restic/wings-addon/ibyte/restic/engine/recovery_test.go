package engine

import (
	"context"
	"encoding/base64"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestRecoveryEscrowRestoresAfterServerAndNodeStateRemoval(t *testing.T) {
	e, r := fixture(t)
	payload := []byte("returning client world")
	if err := os.WriteFile(filepath.Join(r.Root, "world.dat"), payload, 0600); err != nil {
		t.Fatal(err)
	}
	job := perform(t, e, r)
	mustOK(t, job)
	info, err := e.Recovery(r)
	if err != nil {
		t.Fatal(err)
	}
	if info.Server != serverA || info.Host != r.Host || info.Node != r.Node || info.Repository == "" || info.OriginalRoot != r.Root {
		t.Fatal("lost repository identity")
	}
	password, err := base64.StdEncoding.DecodeString(info.PasswordBase64)
	if err != nil || len(password) != 64 || info.CredentialMode != "server" || !info.KeyFinalized {
		t.Fatal("incorrect escrowed password")
	}
	// Only disposable fixture paths. Keep the complete encrypted repository;
	// remove original game files, node state and original credential file.
	if filepath.Base(r.Root) != serverA {
		t.Fatal("unexpected fixture root")
	}
	if err := os.RemoveAll(r.Root); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(e.cfg.State); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(e.cfg.Profiles[r.Profile].PasswordFile); err != nil {
		t.Fatal(err)
	}
	recoveryDir := t.TempDir()
	passwordFile := filepath.Join(recoveryDir, "recovered.password")
	if err := os.WriteFile(passwordFile, password, 0600); err != nil {
		t.Fatal(err)
	}
	target := filepath.Join(recoveryDir, "empty-restore")
	subfolder := filepath.ToSlash(info.OriginalRoot)
	if runtime.GOOS == "windows" {
		subfolder = "/" + strings.ReplaceAll(subfolder, ":", "")
	}
	cmd := exec.CommandContext(context.Background(), e.cfg.Binary, "--repo", info.Repository, "--password-file", passwordFile, "--no-cache", "restore", job.Snapshot+":"+subfolder, "--target", target, "--verify")
	cmd.Env = []string{"SystemRoot=" + os.Getenv("SystemRoot"), "HOME=" + recoveryDir, "TEMP=" + recoveryDir, "TMP=" + recoveryDir}
	if err := cmd.Run(); err != nil {
		t.Fatal("offline recovery using escrowed credential failed:", err)
	}
	found := false
	if err := filepath.WalkDir(target, func(path string, entry os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if !entry.IsDir() && entry.Name() == "world.dat" {
			b, err := os.ReadFile(path)
			if err != nil {
				return err
			}
			if string(b) != string(payload) {
				t.Fatal("wrong recovered content")
			}
			found = true
		}
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	if !found {
		t.Fatal("escrowed repo/snapshot/password failed to recover original world")
	}
}

func TestRecoveryDescriptorRejectsUnapprovedIdentity(t *testing.T) {
	e, r := fixture(t)
	r.Profile = "unknown"
	if _, err := e.Recovery(r); err == nil {
		t.Fatal("unapproved credential profile revealed")
	}
	r.Profile = "test"
	r.Node++
	if _, err := e.Recovery(r); err == nil {
		t.Fatal("wrong node revealed credential")
	}
}

func TestRecoveryEscrowsApprovedS3CredentialFilesWithoutNetwork(t *testing.T) {
	e, r := fixture(t)
	file := filepath.Join(t.TempDir(), "storage.json")
	credentials := []byte(`{"key":"fixture","secret":"fixture"}`)
	if err := os.WriteFile(file, credentials, 0600); err != nil {
		t.Fatal(err)
	}
	e.cfg.Profiles["remote"] = Profile{Backend: "s3", Endpoint: "https://s3.example.invalid", Bucket: "fixture-bucket", Prefix: "fixture", PasswordFile: e.cfg.Profiles["test"].PasswordFile, CredentialsFile: file}
	if err := e.cfg.Validate(); err != nil {
		t.Fatal(err)
	}
	r.Profile = "remote"
	r.Backend = "s3"
	info, err := e.Recovery(r)
	if err != nil {
		t.Fatal(err)
	}
	decoded, err := base64.StdEncoding.DecodeString(info.CredentialsBase64)
	if err != nil || string(decoded) != string(credentials) || info.CredentialsFile != file || !strings.HasPrefix(info.Repository, "s3:https://s3.example.invalid/fixture-bucket/fixture/host-1/node-1/") {
		t.Fatal("incorrect approved S3 recovery descriptor")
	}
}

func TestRetainedRepositoryCannotOverlapNativeDeletionRoots(t *testing.T) {
	base := t.TempDir()
	game := filepath.Join(base, "volumes")
	backups := filepath.Join(base, "backups")
	info := Recovery{Backend: "local", Repository: filepath.Join(base, "repositories", serverA)}
	if !info.Outside(game, backups) {
		t.Fatal("separate repository refused")
	}
	info.Repository = filepath.Join(game, serverA, "restic")
	if info.Outside(game, backups) {
		t.Fatal("repository inside deleted game data accepted")
	}
	info.Repository = filepath.Join(backups, serverA, "restic")
	if info.Outside(game, backups) {
		t.Fatal("repository inside native backup directory accepted")
	}
	info.Repository = base
	if info.Outside(game, backups) {
		t.Fatal("ancestor repository accepted")
	}
}
