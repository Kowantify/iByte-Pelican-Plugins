package engine

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
)

func TestExplicitEmptyRepositoryDeletionAndRetirement(t *testing.T) {
	e, r := fixture(t)
	ctx := context.Background()
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("keep game data"), 0600)
	j := perform(t, e, r)
	mustOK(t, j)
	plan, err := e.RepositoryPlan(ctx, r)
	if err != nil || plan.Snapshots[r.Backup] != j.Snapshot || plan.Deleted {
		t.Fatal("invalid recognized plan", err)
	}
	remove := r
	fingerprint := sha256.Sum256([]byte(plan.Repository))
	remove.RepositoryFingerprint = hex.EncodeToString(fingerprint[:])
	remove.Operation = "repo-delete"
	remove.RequestID = backupB
	if j := perform(t, e, remove); j.Status != "failed" {
		t.Fatal("nonempty repository deleted")
	}
	// Native snapshot forget comes first; a separate UUID represents a new
	// explicit deletion request, never replay a cached failed request as success.
	forget := r
	forget.Operation = "delete"
	mustOK(t, perform(t, e, forget))
	remove.RequestID = "44444444-4444-4444-8444-444444444444"
	info, err := e.Recovery(r)
	if err != nil {
		t.Fatal(err)
	}
	mustOK(t, perform(t, e, remove))
	mustOK(t, perform(t, e, remove))
	if _, err := os.Stat(plan.Repository); !os.IsNotExist(err) {
		t.Fatal("repository leaf remains")
	}
	if b, err := os.ReadFile(filepath.Join(r.Root, "world")); err != nil || string(b) != "keep game data" {
		t.Fatal("game data changed")
	}
	if _, err := os.Stat(info.PasswordFile); err != nil {
		t.Fatal("recovery key removed")
	}
	plan, err = e.RepositoryPlan(ctx, r)
	if err != nil || !plan.Deleted {
		t.Fatal("lost retirement marker", err)
	}
	r.Backup = "55555555-5555-4555-8555-555555555555"
	r.RequestID = r.Backup
	if _, err := e.Submit(ctx, r, func() error { return nil }, nil); err == nil {
		t.Fatal("retired identity automatically reinitialized")
	}
}

func TestRepositoryDeletionRefusesUnrecognizedState(t *testing.T) {
	for _, fault := range []string{"unknown-snapshot", "missing-marker", "wrong-password", "symlink", "lock", "location-change"} {
		t.Run(fault, func(t *testing.T) {
			e, r := fixture(t)
			ctx := context.Background()
			os.WriteFile(filepath.Join(r.Root, "world"), []byte("preserved"), 0600)
			j := perform(t, e, r)
			mustOK(t, j)
			repo, p, _ := e.cfg.repository(r)
			fingerprint := sha256.Sum256([]byte(repo))
			r.RepositoryFingerprint = hex.EncodeToString(fingerprint[:])
			if fault != "unknown-snapshot" {
				if _, err := e.run(ctx, r, "forget", j.Snapshot); err != nil {
					t.Fatal(err)
				}
			}
			switch fault {
			case "unknown-snapshot":
				os.Remove(e.mapPath(r))
			case "missing-marker":
				os.Remove(e.repoMarker(r))
			case "wrong-password":
				os.WriteFile(p.PasswordFile, []byte("disposable-test-password-wrong"), 0600)
			case "symlink":
				if err := os.Symlink(r.Root, filepath.Join(repo, "escape")); err != nil {
					t.Skip("OS denied symlink privilege")
				}
			case "lock":
				os.WriteFile(filepath.Join(repo, "locks", "operator-lock"), []byte("fixture"), 0600)
			case "location-change":
				r.RepositoryFingerprint = "wrong-location"
			}
			r.Operation = "repo-delete"
			r.RequestID = backupB
			if job := perform(t, e, r); job.Status != "failed" {
				t.Fatal("unsafe repository deletion succeeded", fault)
			}
			if _, err := os.Stat(repo); err != nil {
				t.Fatal("repository was removed on refusal")
			}
			if b, err := os.ReadFile(filepath.Join(r.Root, "world")); err != nil || string(b) != "preserved" {
				t.Fatal("game data changed on refusal")
			}
		})
	}
}
