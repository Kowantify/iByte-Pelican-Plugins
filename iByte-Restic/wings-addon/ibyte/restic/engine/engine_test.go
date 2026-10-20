package engine

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"testing"
	"time"
)

const serverA = "11111111-1111-4111-8111-111111111111"
const backupA = "22222222-2222-4222-8222-222222222222"
const backupB = "33333333-3333-4333-8333-333333333333"

func fixture(t *testing.T) (*Engine, Request) {
	t.Helper()
	bin := os.Getenv("RESTIC_TEST_BINARY")
	if bin == "" {
		t.Skip("set RESTIC_TEST_BINARY for real Restic integration")
	}
	base := t.TempDir()
	pass := filepath.Join(base, "password")
	os.WriteFile(pass, []byte("disposable-test-password"), 0600)
	root := filepath.Join(base, "volumes", serverA)
	os.MkdirAll(root, 0700)
	e, err := New(Config{Binary: bin, State: filepath.Join(base, "state"), Node: 1, MaxArchiveBytes: 1 << 20, Profiles: map[string]Profile{"test": {Backend: "local", Root: filepath.Join(base, "repos"), PasswordFile: pass}}})
	if err != nil {
		t.Fatal(err)
	}
	r := Request{RequestID: backupA, Host: 1, Node: 1, Profile: "test", Server: serverA, Backup: backupA, Operation: "create", Root: root}
	if err := e.PrepareServerKey(context.Background(), r, ""); err != nil {
		t.Fatal(err)
	}
	return e, r
}
func perform(t *testing.T, e *Engine, r Request) Job {
	t.Helper()
	_, err := e.Submit(context.Background(), r, func() error { return nil }, nil)
	if err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(time.Minute)
	for time.Now().Before(deadline) {
		j, err := e.Status(r)
		if err != nil {
			// Windows can briefly deny a read while an atomic job replacement is
			// pending. The Panel likewise retries failed status HTTP requests.
			// Do not hide missing/corrupt state or other errors from these tests.
			if runtime.GOOS == "windows" && errors.Is(err, syscall.Errno(32)) {
				time.Sleep(20 * time.Millisecond)
				continue
			}
			t.Fatal(err)
		}
		if j.Status != "running" { // wait for slot bookkeeping to finish as well
			for {
				e.mu.Lock()
				busy := e.busy[r.Server]
				e.mu.Unlock()
				if !busy {
					break
				}
				time.Sleep(time.Millisecond)
			}
			return j
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("job timed out")
	return Job{}
}
func mustOK(t *testing.T, j Job) {
	t.Helper()
	if j.Status != "succeeded" {
		t.Fatalf("%s %s: %s", j.Operation, j.Status, j.Error)
	}
}
func TestRealResticLifecycle(t *testing.T) {
	e, r := fixture(t)
	os.WriteFile(filepath.Join(r.Root, "world.dat"), []byte("world version one"), 0600)
	first := perform(t, e, r)
	mustOK(t, first)
	if first.Bytes != 17 || !snapshotID.MatchString(first.Snapshot) {
		t.Fatalf("bad summary: %+v", first)
	}
	// Duplicate delivery must return the original job, with no new snapshot.
	mustOK(t, perform(t, e, r))
	list, err := e.snapshots(context.Background(), r)
	if err != nil || len(list) != 1 {
		t.Fatalf("duplicate: %v %v", list, err)
	}
	os.WriteFile(filepath.Join(r.Root, "world.dat"), []byte("world version two"), 0600)
	r.Backup = backupB
	second := perform(t, e, r)
	mustOK(t, second)
	if first.Snapshot == second.Snapshot {
		t.Fatal("wrong snapshot")
	}
	r.Backup = backupA
	r.Operation = "restore"
	mustOK(t, perform(t, e, r))
	b, _ := os.ReadFile(filepath.Join(r.Root, "world.dat"))
	if string(b) != "world version one" {
		t.Fatal("wrong restore content")
	}
	var archive bytes.Buffer
	if err := e.Export(context.Background(), r, &archive); err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(&archive)
	h, err := tr.Next()
	if err != nil || h.Name != "world.dat" {
		t.Fatalf("archive: %v %v", h, err)
	}
	b, _ = io.ReadAll(tr)
	if string(b) != "world version one" {
		t.Fatal("archive content")
	}
	r.Operation = "check"
	mustOK(t, perform(t, e, r))
	r.Operation = "stats"
	mustOK(t, perform(t, e, r))
	r.Operation = "delete"
	mustOK(t, perform(t, e, r))
	list, err = e.snapshots(context.Background(), r)
	if err != nil || len(list) != 1 || list[0].ID != second.Snapshot {
		t.Fatal("deleted unrelated snapshot")
	}
}
func TestMidRestoreFailureRollsBack(t *testing.T) {
	e, r := fixture(t)
	os.WriteFile(filepath.Join(r.Root, "a"), []byte("old snapshot"), 0600)
	os.WriteFile(filepath.Join(r.Root, "b"), []byte("snapshot b"), 0600)
	mustOK(t, perform(t, e, r))
	os.WriteFile(filepath.Join(r.Root, "a"), []byte("active world"), 0600)
	os.WriteFile(filepath.Join(r.Root, "c"), []byte("current c"), 0600)
	e.FailCommit = func(i int) error {
		if i == 1 {
			return errors.New("forced ENOSPC")
		}
		return nil
	}
	r.Operation = "restore"
	j := perform(t, e, r)
	if j.Status != "failed" {
		t.Fatal(j)
	}
	b, _ := os.ReadFile(filepath.Join(r.Root, "a"))
	if string(b) != "active world" {
		t.Fatal("rollback lost active world")
	}
	if _, err := os.Stat(filepath.Join(r.Root, "c")); err != nil {
		t.Fatal("rollback lost current file")
	}
}
func TestRestartUnknownBlocksMutations(t *testing.T) {
	e, r := fixture(t)
	j := Job{ID: id(r), Server: r.Server, Status: "running"}
	if err := e.saveJob(&j); err != nil {
		t.Fatal(err)
	}
	restarted, err := New(e.cfg)
	if err != nil {
		t.Fatal(err)
	}
	j, err = restarted.Status(r)
	if err != nil || j.Status != "unknown" {
		t.Fatal(j, err)
	}
	if !contains(restarted.RecoveryServers(), r.Server) {
		t.Fatal("startup quarantine lost affected server")
	}
	r.Backup = backupB
	if _, err = restarted.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("replayed uncertain operation")
	}
}
func TestIdentityAndInjectionRejected(t *testing.T) {
	e, r := fixture(t)
	for _, field := range []string{"server", "backup", "profile", "node", "host", "backend"} {
		bad := r
		switch field {
		case "server":
			bad.Server = "../outside"
		case "backup":
			bad.Backup = "$(touch pwned)"
		case "profile":
			bad.Profile = "unknown"
		case "node":
			bad.Node = 2
		case "host":
			bad.Host = 0
		case "backend":
			bad.Backend = "s3"
		}
		if _, _, err := e.cfg.repository(bad); err == nil {
			t.Fatal(field)
		}
	}
	r.Ignore = []string{"--password-command=evil"}
	if _, err := e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("option accepted")
	}
}

func TestExistingRepositoryNotAdoptedOrWiped(t *testing.T) {
	e, r := fixture(t)
	repo, _, err := e.cfg.repository(r)
	if err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(repo, 0700)
	sentinel := filepath.Join(repo, "existing-data")
	os.WriteFile(sentinel, []byte("preserve"), 0600)
	j := perform(t, e, r)
	if j.Status != "failed" {
		t.Fatal(j)
	}
	b, err := os.ReadFile(sentinel)
	if err != nil || string(b) != "preserve" {
		t.Fatal("existing repository touched")
	}
}
func TestCancellationNeverCreatesSuccessMapping(t *testing.T) {
	e, r := fixture(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	finished := make(chan Job, 1)
	if _, err := e.Submit(ctx, r, func() error { return nil }, func(j Job) { finished <- j }); err != nil {
		t.Fatal(err)
	}
	select {
	case j := <-finished:
		if j.Status != "failed" {
			t.Fatal(j)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("cancellation hung")
	}
	if _, err := e.Mapping(r); !os.IsNotExist(err) {
		t.Fatal("cancelled work created mapping", err)
	}
}
func TestCorruptDurableStateFailsClosed(t *testing.T) {
	e, _ := fixture(t)
	os.WriteFile(filepath.Join(e.cfg.State, "job-corrupt.json"), []byte("{invalid"), 0600)
	if _, err := New(e.cfg); err == nil {
		t.Fatal("corrupt state ignored")
	}
}
func TestCompletionPersistenceFailureQuarantinesServer(t *testing.T) {
	e, r := fixture(t)
	e.FailPersist = func(j Job) error {
		if j.Status != "running" {
			return errors.New("forced state disk full")
		}
		return nil
	}
	done := make(chan Job, 1)
	if _, err := e.Submit(context.Background(), r, func() error { return errors.New("operation failed") }, func(j Job) { done <- j }); err != nil {
		t.Fatal(err)
	}
	j := <-done
	if j.Status != "unknown" {
		t.Fatal(j)
	}
	j, err := e.Status(r)
	if err != nil || j.Status != "unknown" {
		t.Fatal(j, err)
	}
	j, err = e.Submit(context.Background(), r, func() error { return nil }, nil)
	if err != nil || j.Status != "unknown" {
		t.Fatal("duplicate request lost quarantine", j, err)
	}
	r.Backup = backupB
	if _, err = e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("uncertain server accepted new work")
	}
}
func TestSymlinkRejected(t *testing.T) {
	base := t.TempDir()
	outside := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(base, "escape")); err != nil {
		t.Skip("OS denied symlink privilege")
	}
	if safeTree(base) == nil {
		t.Fatal("symlink accepted")
	}
}
func TestArchiveLimit(t *testing.T) {
	root := t.TempDir()
	os.WriteFile(filepath.Join(root, "world"), make([]byte, 1000), 0600)
	var out bytes.Buffer
	if archive(root, &out, 100) == nil {
		t.Fatal("limit ignored")
	}
	if out.Len() != 0 {
		t.Fatal("sent partial archive on preflight failure")
	}
}
func TestWrongPasswordNoSecretLeak(t *testing.T) {
	e, r := fixture(t)
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("ok"), 0600)
	mustOK(t, perform(t, e, r))
	_, p, _ := e.cfg.repository(r)
	os.WriteFile(p.PasswordFile, []byte("highly-secret-wrong-password"), 0600)
	r.Operation = "check"
	j := perform(t, e, r)
	b, _ := json.Marshal(j)
	if j.Status != "failed" || strings.Contains(string(b), "highly-secret") {
		t.Fatal(string(b))
	}
}
func TestConcurrentWorkRejected(t *testing.T) {
	e, r := fixture(t)
	start := make(chan struct{})
	done := make(chan struct{})
	finished := make(chan struct{})
	_, err := e.Submit(context.Background(), r, func() error { close(start); <-done; return errors.New("injected failure") }, func(Job) { close(finished) })
	if err != nil {
		t.Fatal(err)
	}
	<-start
	r.Backup = backupB
	if _, err = e.Submit(context.Background(), r, func() error { return nil }, nil); err == nil {
		t.Fatal("concurrent server mutation accepted")
	}
	close(done)
	<-finished
}
