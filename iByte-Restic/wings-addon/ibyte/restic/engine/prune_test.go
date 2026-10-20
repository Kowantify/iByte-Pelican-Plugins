package engine

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/rand"
	"errors"
	"io"
	"os"
	"path/filepath"
	"testing"
)

func TestRealPrunePreservesRetainedSnapshotsAndReclaimsStorage(t *testing.T) {
	e, r := fixture(t)
	payload := make([]byte, 3<<20)
	if _, err := rand.Read(payload); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(r.Root, "world"), payload, 0600); err != nil {
		t.Fatal(err)
	}
	mustOK(t, perform(t, e, r))
	keep := []byte("retained and locked at Panel: complete current game world")
	if err := os.WriteFile(filepath.Join(r.Root, "world"), keep, 0600); err != nil {
		t.Fatal(err)
	}
	r.Backup = backupB
	mustOK(t, perform(t, e, r))
	r.Operation = "stats"
	before := perform(t, e, r)
	mustOK(t, before)
	r.Backup = backupA
	r.Operation = "delete"
	mustOK(t, perform(t, e, r))
	r.Operation = "prune"
	mustOK(t, perform(t, e, r)) // Deleted mapping is a valid maintenance anchor.
	mustOK(t, perform(t, e, r)) // Durable replay is idempotent.
	list, err := e.snapshots(context.Background(), r)
	if err != nil || len(list) != 1 || !contains(list[0].Tags, "backup:"+backupB) {
		t.Fatalf("prune lost retained snapshot: %+v %v", list, err)
	}
	r.Backup = backupB
	r.Operation = "stats"
	r.RequestID = "44444444-4444-4444-8444-444444444444"
	after := perform(t, e, r)
	mustOK(t, after)
	if before.Repository.FileBytes == nil || after.Repository.FileBytes == nil || *after.Repository.FileBytes >= *before.Repository.FileBytes {
		t.Fatalf("prune did not reclaim unreferenced data: %+v %+v", before.Repository, after.Repository)
	}
	var out bytes.Buffer
	if err := e.Export(context.Background(), r, &out); err != nil {
		t.Fatal(err)
	}
	archive := tar.NewReader(&out)
	if _, err := archive.Next(); err != nil {
		t.Fatal(err)
	}
	recovered, err := io.ReadAll(archive)
	if err != nil || !bytes.Equal(recovered, keep) {
		t.Fatalf("remaining backup is not recoverable: %v", err)
	}
	r.Operation = "check"
	mustOK(t, perform(t, e, r))
}

func TestPruneRefusesUnprovisionedRepoAndInterruptedWork(t *testing.T) {
	e, r := fixture(t)
	r.Operation = "prune"
	if j := perform(t, e, r); j.Status != "failed" {
		t.Fatalf("unprovisioned prune accepted: %+v", j)
	}
	r.Operation = "create"
	mustOK(t, perform(t, e, r))
	r.Operation = "prune"
	r.RequestID = "55555555-5555-4555-8555-555555555555"
	e.FailPersist = func(j Job) error {
		if j.Operation == "prune" && j.Status == "succeeded" {
			return errors.New("fixture disk full persisting prune")
		}
		return nil
	}
	if j := perform(t, e, r); j.Status != "unknown" {
		t.Fatalf("lost persistence reported success: %+v", j)
	}
	r.Operation = "create"
	r.Backup = backupB
	if _, err := e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("interrupted prune did not quarantine repo")
	}
	restarted, err := New(e.cfg)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := restarted.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("daemon restart cleared interrupted prune quarantine")
	}
}
