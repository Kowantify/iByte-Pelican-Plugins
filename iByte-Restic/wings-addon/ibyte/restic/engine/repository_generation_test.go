package engine

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestDeletedRepositoryFreshGenerationKeyHistoryAndRestore(t *testing.T) {
	e, original := fixture(t)
	ctx := context.Background()
	world := filepath.Join(original.Root, "world")
	os.WriteFile(world, []byte("old recovery point"), 0600)
	mustOK(t, perform(t, e, original))
	oldInfo, _ := e.Recovery(original)
	oldRepo, oldProfile, _ := e.cfg.repository(original)
	// Simulate an offsite copy to prove the preserved password still recovers it.
	offsite := filepath.Join(t.TempDir(), "old-repository")
	if err := filepath.WalkDir(oldRepo, func(path string, d os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, _ := filepath.Rel(oldRepo, path)
		target := filepath.Join(offsite, rel)
		if d.IsDir() {
			return os.MkdirAll(target, 0700)
		}
		data, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		return os.WriteFile(target, data, 0600)
	}); err != nil {
		t.Fatal(err)
	}
	remove, _ := forceRequest(t, e, original)
	mustOK(t, perform(t, e, remove))
	state, err := e.RepositoryState(original)
	if err != nil || state.Exists || !state.Initialized {
		t.Fatal("deleted repository not absent", err)
	}
	next := original
	previous := uint64(0)
	next.Generation, next.PreviousGeneration = 1, &previous
	next.Backup, next.RequestID = backupB, backupB
	if err := e.PrepareServerKey(ctx, next, ""); err != nil {
		t.Fatal(err)
	}
	fresh, err := e.Recovery(next)
	if err != nil || fresh.PasswordBase64 == oldInfo.PasswordBase64 || fresh.Repository == oldInfo.Repository {
		t.Fatal("generation did not change key/location", err)
	}
	if filepath.Dir(fresh.Repository) != filepath.Dir(oldInfo.Repository) {
		t.Fatal("replacement must be a sibling, never inside the retired leaf")
	}
	if again, err := New(e.cfg); err != nil {
		t.Fatal(err)
	} else {
		e = again
	}
	if err := e.PrepareServerKey(ctx, next, ""); err != nil {
		t.Fatal(err)
	}
	stable, _ := e.Recovery(next)
	if stable.PasswordBase64 != fresh.PasswordBase64 {
		t.Fatal("retry/restart regenerated the replacement password")
	}
	os.WriteFile(world, []byte("new generation recovery point"), 0600)
	next.PreviousGeneration = nil
	mustOK(t, perform(t, e, next))
	// A late old force request, even with a NEW request UUID, targets only g0.
	remove.RequestID = "44444444-4444-4444-8444-444444444444"
	mustOK(t, perform(t, e, remove))
	var archive bytes.Buffer
	if err := e.Export(ctx, next, &archive); err != nil || archive.Len() == 0 {
		t.Fatal("late deletion touched replacement", err)
	}
	os.WriteFile(world, []byte("changed game"), 0600)
	next.Operation, next.RequestID = "restore", "55555555-5555-4555-8555-555555555555"
	mustOK(t, perform(t, e, next))
	if data, err := os.ReadFile(world); err != nil || string(data) != "new generation recovery point" {
		t.Fatal("replacement snapshot restore mismatch", err)
	}
	if _, err := e.runProfile(ctx, original, offsite, oldProfile, "snapshots", "--json"); err != nil {
		t.Fatal("old key no longer unlocks copied original", err)
	}
	oldProfile.PasswordFile = fresh.PasswordFile
	if _, err := e.runProfile(ctx, original, offsite, oldProfile, "snapshots", "--json"); err == nil {
		t.Fatal("replacement key opened original generation")
	}
	oldAgain, _ := e.Recovery(original)
	if oldAgain.PasswordBase64 != oldInfo.PasswordBase64 {
		t.Fatal("old key file overwritten")
	}
}

func TestManualRepositoryRemovalRenewsWithoutWipingExistingData(t *testing.T) {
	e, r := fixture(t)
	ctx := context.Background()
	initial, err := e.RepositoryState(r)
	if err != nil || initial.Exists || initial.Initialized {
		t.Fatal("prepared key must not look like a deleted initialized repository", err)
	}
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("keep game"), 0600)
	mustOK(t, perform(t, e, r))
	previous := uint64(0)
	next := r
	next.Generation, next.PreviousGeneration = 1, &previous
	if err := e.PrepareServerKey(ctx, next, ""); err == nil {
		t.Fatal("renewal accepted while original repository exists")
	}
	state, err := e.RepositoryState(r)
	if err != nil || !state.Exists {
		t.Fatal("existing repo misidentified as missing", err)
	}
	repo, _, _ := e.cfg.baseRepository(r)
	if err := os.RemoveAll(repo); err != nil {
		t.Fatal(err)
	} // disposable fixture only
	if err := e.PrepareServerKey(ctx, next, ""); err != nil {
		t.Fatal(err)
	}
	next.PreviousGeneration = nil
	next.Backup, next.RequestID = backupB, backupB
	mustOK(t, perform(t, e, next))
	if e.cfg.keyPath(r) == e.cfg.keyPath(next) || e.repoMarker(r) == e.repoMarker(next) || e.mapPath(r) == e.mapPath(next) {
		t.Fatal("generation state identities alias")
	}
	bad := next
	bad.Generation = 3
	bad.PreviousGeneration = &previous
	if err := e.PrepareServerKey(ctx, bad, ""); err == nil {
		t.Fatal("nonconsecutive generation transition accepted")
	}
	if data, err := os.ReadFile(filepath.Join(r.Root, "world")); err != nil || string(data) != "keep game" {
		t.Fatal("game changed during generation renewal", err)
	}
}
