package engine

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestRealStorageAccountingAndRecovery(t *testing.T) {
	e, r := fixture(t)
	payload := bytes.Repeat([]byte("repeated game world content\n"), 16000)
	if err := os.WriteFile(filepath.Join(r.Root, "world"), payload, 0600); err != nil {
		t.Fatal(err)
	}
	first := perform(t, e, r)
	mustOK(t, first)
	if first.Repository == nil || first.Repository.SnapshotCount != 1 || first.Repository.FileBytes == nil {
		t.Fatal("new backup must automatically capture repository storage")
	}
	r.Backup = backupB
	second := perform(t, e, r)
	mustOK(t, second)
	if second.Repository == nil || second.Repository.SnapshotCount != 2 || second.Repository.LogicalBytes != int64(2*len(payload)) {
		t.Fatal("every backup must carry a fresh measured repository snapshot")
	}
	if first.Storage == nil || second.Storage == nil || first.Storage.LogicalBytes != int64(len(payload)) || second.Bytes != int64(len(payload)) {
		t.Fatalf("logical size must remain independent of dedup: %+v %+v", first, second)
	}
	if first.Storage.PackedBytes <= 0 || first.Storage.PackedBytes >= first.Storage.AddedBytes || second.Storage.PackedBytes >= first.Storage.PackedBytes {
		t.Fatalf("compression/dedup not represented: %+v %+v", first.Storage, second.Storage)
	}
	// The same tagged snapshot must recover counters without another backup.
	recovered := Job{}
	if err := e.execute(context.Background(), r, &recovered); err != nil {
		t.Fatal(err)
	}
	if recovered.Storage == nil || *recovered.Storage != *second.Storage || recovered.Bytes != second.Bytes || recovered.Snapshot != second.Snapshot {
		t.Fatalf("lost summary on recovery: %+v", recovered)
	}
	r.Operation = "stats"
	stats := perform(t, e, r)
	mustOK(t, stats)
	s := stats.Repository
	if s == nil || s.SnapshotCount != 2 || s.LogicalBytes != int64(2*len(payload)) || s.StoredBytes <= 0 || s.UncompressedBytes <= s.StoredBytes || s.CompressionRatio <= 1 || s.FileBytes == nil || *s.FileBytes < s.StoredBytes || s.Snapshots[backupA] != *first.Storage || s.Snapshots[backupB] != *second.Storage {
		t.Fatalf("incorrect repository accounting: %+v", s)
	}
	// Removing the first snapshot does not remove chunks needed by the second.
	r.Operation = "delete"
	r.Backup = backupA
	mustOK(t, perform(t, e, r))
	r.Backup = backupB
	r.Operation = "prune"
	mustOK(t, perform(t, e, r))
	var out bytes.Buffer
	if err := e.Export(context.Background(), r, &out); err != nil {
		t.Fatal(err)
	}
	if out.Len() == 0 {
		t.Fatal("remaining deduplicated snapshot lost its archive")
	}
	if *second.Storage != s.Snapshots[backupB] {
		t.Fatal("historical additions must not be reassigned")
	}
	e.cfg.MaxArchiveBytes = 1024
	if err := e.Export(context.Background(), r, &bytes.Buffer{}); err == nil {
		t.Fatal("deduplicated added bytes must never replace logical archive safety limit")
	}
}
