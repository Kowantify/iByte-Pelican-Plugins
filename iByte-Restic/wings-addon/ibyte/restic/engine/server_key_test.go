package engine

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
)

func TestUniqueServerKeysAndIsolation(t *testing.T) {
	e, r := fixture(t)
	ctx := context.Background()
	if err := e.PrepareServerKey(ctx, r, ""); err != nil {
		t.Fatal(err)
	}
	a, err := e.Recovery(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := e.PrepareServerKey(ctx, r, ""); err != nil {
		t.Fatal(err)
	}
	again, _ := e.Recovery(r)
	if a.PasswordBase64 != again.PasswordBase64 {
		t.Fatal("repeat preparation changed password")
	}
	s := r
	s.Server = backupB
	s.Root = filepath.Join(filepath.Dir(r.Root), s.Server)
	os.MkdirAll(s.Root, 0700)
	if err := e.PrepareServerKey(ctx, s, ""); err != nil {
		t.Fatal(err)
	}
	b, _ := e.Recovery(s)
	if a.PasswordBase64 == b.PasswordBase64 || a.PasswordBase64 == base64.StdEncoding.EncodeToString([]byte("disposable-test-password")) {
		t.Fatal("server keys were shared")
	}
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("server A"), 0600)
	mustOK(t, perform(t, e, r))
	repo, p, _ := e.cfg.repository(r)
	p.PasswordFile = b.PasswordFile
	if _, err := e.runProfile(ctx, r, repo, p, "snapshots", "--json"); err == nil {
		t.Fatal("server B password opened A")
	}
	p.PasswordFile = e.cfg.Profiles[r.Profile].PasswordFile
	if _, err := e.runProfile(ctx, r, repo, p, "snapshots", "--json"); err == nil {
		t.Fatal("shared profile password opened new server repo")
	}
}

func TestLegacyServerKeyUpgradeRequiresEscrowReceipt(t *testing.T) {
	e, r := fixture(t)
	os.Remove(e.cfg.keyPath(r))
	os.Remove(e.cfg.keyPasswordPath(r))
	ctx := context.Background()
	repo, p, _ := e.cfg.baseRepository(r)
	os.MkdirAll(repo, 0700)
	// Construct a real old v3 shared-profile repository, not a legacy import.
	if _, err := e.runProfile(ctx, r, repo, p, "init"); err != nil {
		t.Fatal(err)
	}
	if err := writeJSON(e.repoMarker(r), map[string]string{"server": r.Server, "profile": r.Profile}); err != nil {
		t.Fatal(err)
	}
	if _, err := e.Submit(ctx, r, func() error { return nil }, nil); err == nil {
		t.Fatal("old Panel dispatched backup without unique credential escrow")
	}
	if err := e.PrepareServerKey(ctx, r, ""); err != nil {
		t.Fatal(err)
	}
	info, err := e.Recovery(r)
	if err != nil {
		t.Fatal(err)
	}
	if info.CredentialMode != "server" || info.KeyFinalized || info.PreviousBase64 != base64.StdEncoding.EncodeToString([]byte("disposable-test-password")) {
		t.Fatal("lost prepared key history")
	}
	if _, err := e.Submit(ctx, r, func() error { return nil }, nil); err == nil {
		t.Fatal("backup dispatched before escrow acknowledgement")
	}
	if _, err := e.runProfile(ctx, r, repo, p, "snapshots", "--json"); err != nil {
		t.Fatal("old key retired before escrow")
	}
	if err := e.PrepareServerKey(ctx, r, "wrong"); err == nil {
		t.Fatal("invalid receipt accepted")
	}
	restarted, err := New(e.cfg)
	if err != nil {
		t.Fatal(err)
	}
	if err := restarted.PrepareServerKey(ctx, r, ""); err != nil {
		t.Fatal(err)
	}
	password, _ := base64.StdEncoding.DecodeString(info.PasswordBase64)
	digest := sha256.Sum256(password)
	if err := restarted.PrepareServerKey(ctx, r, hex.EncodeToString(digest[:])); err != nil {
		t.Fatal(err)
	}
	if err := restarted.PrepareServerKey(ctx, r, hex.EncodeToString(digest[:])); err != nil {
		t.Fatal("finalize replay", err)
	}
	if _, err := restarted.runProfile(ctx, r, repo, p, "snapshots", "--json"); err == nil {
		t.Fatal("shared wrapper was not retired")
	}
	if _, err := restarted.snapshots(ctx, r); err != nil {
		t.Fatal("new key cannot open repo", err)
	}
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("upgraded"), 0600)
	mustOK(t, perform(t, restarted, r))
}
