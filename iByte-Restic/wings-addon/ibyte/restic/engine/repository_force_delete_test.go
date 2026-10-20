package engine

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
)

func forceRequest(t *testing.T, e *Engine, r Request) (Request, string) {
	t.Helper()
	repo, _, err := e.cfg.baseRepository(r)
	if err != nil {
		t.Fatal(err)
	}
	hash := sha256.Sum256([]byte(repo))
	r.Operation, r.RequestID = "repo-force-delete", backupB
	r.RepositoryFingerprint = hex.EncodeToString(hash[:])
	return r, repo
}

func TestForceDeletesEntireRepositoryWithoutMappingsOrPassword(t *testing.T) {
	e, r := fixture(t)
	game := filepath.Join(r.Root, "world")
	os.WriteFile(game, []byte("preserved game"), 0600)
	mustOK(t, perform(t, e, r))
	r, repo := forceRequest(t, e, r)
	// All conditions that ordinary deletion refuses are intentional force inputs.
	os.Remove(e.mapPath(r))
	os.Remove(e.repoMarker(r))
	os.WriteFile(filepath.Join(repo, "config"), []byte("corrupt repository"), 0600)
	os.WriteFile(filepath.Join(repo, "locks", "external-stale-lock"), []byte("stale"), 0600)
	os.WriteFile(e.cfg.keyPasswordPath(r), []byte("wrong password"), 0600)
	neighbor := filepath.Join(filepath.Dir(repo), "44444444-4444-4444-8444-444444444444")
	os.MkdirAll(neighbor, 0700)
	os.WriteFile(filepath.Join(neighbor, "keep"), []byte("other server"), 0600)
	mustOK(t, perform(t, e, r))
	mustOK(t, perform(t, e, r))
	for path, want := range map[string]string{game: "preserved game", filepath.Join(neighbor, "keep"): "other server"} {
		if b, err := os.ReadFile(path); err != nil || string(b) != want {
			t.Fatal("protected or neighboring data changed", path, err)
		}
	}
	if _, err := os.Stat(repo); !os.IsNotExist(err) {
		t.Fatal("nonempty repository not removed")
	}
	if _, err := os.Stat(e.cfg.keyPasswordPath(r)); err != nil {
		t.Fatal("server key removed")
	}
	r.Operation, r.Backup, r.RequestID = "create", backupB, backupB
	if _, err := e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("force-deleted repository reinitialized")
	}
}

func TestForceMissingRepositoryAndInterruptedWorkRetainsProtection(t *testing.T) {
	e, r := fixture(t)
	r, repo := forceRequest(t, e, r)
	journal := filepath.Join(e.cfg.State, "restore-"+r.Server+".json")
	os.WriteFile(journal, []byte("retained restore journal"), 0600)
	old := r
	old.Operation, old.RequestID = "create", backupA
	j := Job{ID: id(old), Server: r.Server, Status: "unknown"}
	if err := e.saveJob(&j); err != nil {
		t.Fatal(err)
	}
	mustOK(t, perform(t, e, r))
	if _, err := os.Stat(repo); !os.IsNotExist(err) {
		t.Fatal("missing repository was recreated")
	}
	if _, err := os.Stat(journal); err != nil || !e.uncertain(r.Server) {
		t.Fatal("force deletion cleared restore protection")
	}
	var marker repositoryMarker
	if readJSON(e.repoMarker(r), &marker) != nil || !marker.Deleted {
		t.Fatal("missing repository not retired")
	}
}

func TestForceDeletionPathIsolationAndActiveWork(t *testing.T) {
	for _, fault := range []string{"fingerprint", "busy", "wrong-node", "bad-server", "symlink", "protected-root"} {
		t.Run(fault, func(t *testing.T) {
			e, r := fixture(t)
			r, repo := forceRequest(t, e, r)
			os.MkdirAll(repo, 0700)
			os.WriteFile(filepath.Join(repo, "keep"), []byte("repository"), 0600)
			switch fault {
			case "fingerprint":
				r.RepositoryFingerprint = "wrong"
			case "busy":
				e.busy[r.Server] = true
			case "wrong-node":
				r.Node++
			case "bad-server":
				r.Server = "../escape"
			case "symlink":
				if err := os.Symlink(r.Root, filepath.Join(repo, "escape")); err != nil {
					t.Skip("OS denied symlink privilege")
				}
			case "protected-root":
				if e.ValidateForceDeletion(r, filepath.Dir(repo)) == nil {
					t.Fatal("native data overlap accepted")
				}
				r.Root = filepath.Dir(repo)
			}
			if fault == "busy" || fault == "wrong-node" || fault == "bad-server" {
				if _, err := e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
					t.Fatal("invalid or busy request accepted")
				}
			} else {
				if j := perform(t, e, r); j.Status != "failed" {
					t.Fatal("unsafe force deletion succeeded", fault)
				}
			}
			if b, err := os.ReadFile(filepath.Join(repo, "keep")); err != nil || string(b) != "repository" {
				t.Fatal("repository modified on refusal", fault, err)
			}
		})
	}
}
