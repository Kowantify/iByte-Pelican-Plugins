package engine

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestRetainedSnapshotProofRequiresExistingOwnedMapping(t *testing.T) {
	e, r := fixture(t)
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("retained world"), 0600)
	j := perform(t, e, r)
	mustOK(t, j)
	ctx := context.Background()
	m, err := e.VerifySnapshot(ctx, r)
	if err != nil || m.Snapshot != j.Snapshot {
		t.Fatal("valid proof failed", err)
	}
	wrong := r
	wrong.Backup = backupB
	if _, err := e.VerifySnapshot(ctx, wrong); err == nil {
		t.Fatal("unmapped backup proof accepted")
	}
	wrong = r
	wrong.Root = filepath.Dir(r.Root)
	if _, err := e.VerifySnapshot(ctx, wrong); err == nil {
		t.Fatal("wrong original root accepted")
	}
	e.mu.Lock()
	e.busy[r.Server] = true
	e.mu.Unlock()
	if _, err := e.VerifySnapshot(ctx, r); err == nil {
		t.Fatal("busy repository accepted")
	}
	e.mu.Lock()
	delete(e.busy, r.Server)
	e.mu.Unlock()
	if _, err := e.run(ctx, r, "forget", j.Snapshot); err != nil {
		t.Fatal(err)
	}
	if _, err := e.VerifySnapshot(ctx, r); err == nil {
		t.Fatal("externally forgotten snapshot accepted")
	}
}
