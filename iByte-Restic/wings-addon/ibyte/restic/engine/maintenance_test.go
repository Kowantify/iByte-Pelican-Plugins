package engine

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

func TestRetainedMaintenanceAfterGameDirectoryRemoval(t *testing.T) {
	e, r := fixture(t)
	if err := os.WriteFile(filepath.Join(r.Root, "world.dat"), []byte("retained world"), 0600); err != nil {
		t.Fatal(err)
	}
	mustOK(t, perform(t, e, r))
	repo, _, err := e.cfg.repository(r)
	if err != nil {
		t.Fatal(err)
	}
	fingerprint := sha256.Sum256([]byte(repo))
	r.RepositoryFingerprint = hex.EncodeToString(fingerprint[:])
	if filepath.Base(r.Root) != serverA {
		t.Fatal("unexpected disposable game root")
	}
	if err := os.RemoveAll(r.Root); err != nil {
		t.Fatal(err)
	}
	for index, operation := range []string{"check", "stats", "prune"} {
		r.Operation = operation
		r.RequestID = fmt.Sprintf("00000000-0000-4000-8000-%012d", index+1)
		if err := e.ValidateMaintenance(r, r.Root); err != nil {
			t.Fatal(operation, err)
		}
		job := perform(t, e, r)
		mustOK(t, job)
		if operation != "check" && (job.Repository == nil || job.Repository.SnapshotCount != 1) {
			t.Fatal("retained maintenance lost its snapshot/accounting", job)
		}
	}
	if _, err := os.Stat(r.Root); !os.IsNotExist(err) {
		t.Fatal("maintenance recreated game files")
	}
	r.RepositoryFingerprint = "incorrect"
	if e.ValidateMaintenance(r) == nil {
		t.Fatal("wrong captured location accepted")
	}
	r.Operation = "create"
	if e.ValidateMaintenance(r) == nil {
		t.Fatal("creation accepted through maintenance")
	}
}
