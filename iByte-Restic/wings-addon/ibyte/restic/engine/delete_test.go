package engine

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestDeleteExternallyForgottenSnapshot(t *testing.T) {
	e, r := fixture(t)
	ctx := context.Background()
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("first world"), 0600)
	first := perform(t, e, r)
	mustOK(t, first)
	r.Backup = backupB
	os.WriteFile(filepath.Join(r.Root, "world"), []byte("second world"), 0600)
	mustOK(t, perform(t, e, r))
	// Simulate an operator's external removal, only in this disposable repo.
	if _, err := e.run(ctx, r, "forget", first.Snapshot); err != nil {
		t.Fatal(err)
	}
	r.Backup, r.Operation = backupA, "delete"
	mustOK(t, perform(t, e, r))
	m, err := e.Mapping(r)
	if err != nil || !m.Deleted {
		t.Fatalf("absent mapped snapshot not durably reconciled: %+v %v", m, err)
	}
	mustOK(t, perform(t, e, r)) // duplicate delivery remains idempotent
	list, err := e.snapshots(ctx, r)
	if err != nil || len(list) != 1 || !contains(list[0].Tags, "backup:"+backupB) {
		t.Fatalf("another snapshot was affected: %+v %v", list, err)
	}
	r.Backup = backupB
	if err := e.Export(ctx, r, &bytes.Buffer{}); err != nil {
		t.Fatalf("remaining snapshot no longer downloads: %v", err)
	}
}

func TestDeleteDoesNotInferAbsenceFromMappingOrRepositoryErrors(t *testing.T) {
	for _, fault := range []string{"missing-mapping", "wrong-password", "wrong-root"} {
		t.Run(fault, func(t *testing.T) {
			e, r := fixture(t)
			os.WriteFile(filepath.Join(r.Root, "world"), []byte("protected world"), 0600)
			mustOK(t, perform(t, e, r))
			m, err := e.Mapping(r)
			if err != nil {
				t.Fatal(err)
			}
			_, profile, _ := e.cfg.repository(r)
			password := profile.PasswordFile
			originalPassword, _ := os.ReadFile(password)
			switch fault {
			case "missing-mapping":
				if err := os.Remove(e.mapPath(r)); err != nil {
					t.Fatal(err)
				}
			case "wrong-password":
				os.WriteFile(password, []byte("disposable-test-password-wrong"), 0600)
			case "wrong-root":
				// Valid repo and snapshot ID, but original root no longer matches.
				newRoot := filepath.Join(filepath.Dir(r.Root), "moved-root")
				os.MkdirAll(newRoot, 0700)
				r.Root = newRoot
			}
			r.Operation = "delete"
			if job := perform(t, e, r); job.Status != "failed" {
				t.Fatalf("%s falsely became verified absence: %+v", fault, job)
			}
			if fault == "wrong-password" {
				os.WriteFile(password, originalPassword, 0600)
			}
			list, err := e.snapshots(context.Background(), r)
			if err != nil || len(list) != 1 || list[0].ID != m.Snapshot {
				t.Fatalf("failed reconciliation modified repository: %+v %v", list, err)
			}
		})
	}
}
