package engine

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
)

func (e *Engine) ValidateForceDeletion(r Request, protected ...string) error {
	repo, p, err := e.cfg.baseRepository(r)
	if err != nil {
		return err
	}
	if p.Backend != "local" || !(Recovery{Backend: "local", Repository: repo}).Outside(protected...) {
		return errors.New("repository overlaps native protected storage or backend unsupported")
	}
	return nil
}

// Password-confirmed Panel root override. Identity and path are still enforced;
// snapshot mappings, Restic passwords, damaged config and locks are irrelevant.
// No Restic invocation, game-file deletion, key deletion or implicit re-init.
func (e *Engine) forceDeleteRepository(ctx context.Context, r Request) error {
	repo, p, err := e.cfg.baseRepository(r)
	if err != nil {
		return err
	}
	if p.Backend != "local" {
		return errors.New("force deletion supports local repositories only")
	}
	fingerprint := sha256.Sum256([]byte(repo))
	if r.RepositoryFingerprint != hex.EncodeToString(fingerprint[:]) {
		return errors.New("confirmed repository location mismatch")
	}
	if !(Recovery{Backend: "local", Repository: repo}).Outside(r.Root, e.cfg.State) {
		return errors.New("repository overlaps protected data")
	}
	if err := trustedPath(repo); err != nil {
		return err
	}
	if _, err := os.Lstat(repo); err == nil {
		if err := safeTree(repo); err != nil {
			return err
		}
	} else if !os.IsNotExist(err) {
		return err
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	// Retire even an already missing repository. Repeated explicit force requests
	// can finish partial removal; automatic backup creation remains prohibited.
	if err := writeJSON(e.repoMarker(r), repositoryMarker{Server: r.Server, Profile: r.Profile, Deleted: true}); err != nil {
		return err
	}
	if err := os.RemoveAll(repo); err != nil {
		return errors.New("repository removal incomplete; retry explicit force deletion")
	}
	if _, err := os.Lstat(repo); !os.IsNotExist(err) {
		return errors.New("repository removal not verified")
	}
	return nil
}
