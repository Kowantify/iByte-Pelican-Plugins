package engine

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
)

// Retained repositories no longer require a live game-server directory. They
// still require an approved identity, captured location, provisioning marker,
// committed unique key and the ordinary engine concurrency/quarantine checks.
func (e *Engine) ValidateMaintenance(r Request, protected ...string) error {
	if r.Operation != "check" && r.Operation != "stats" && r.Operation != "prune" {
		return errors.New("unsupported retained maintenance operation")
	}
	return e.validateRetainedRepository(r, protected...)
}

func (e *Engine) ValidateRetainedExport(r Request, protected ...string) error {
	if r.Operation != "download" || (r.ArchiveFormat != "tar" && r.ArchiveFormat != "tar.gz") {
		return errors.New("unsupported retained export request")
	}
	return e.validateRetainedRepository(r, protected...)
}

func (e *Engine) validateRetainedRepository(r Request, protected ...string) error {
	repo, p, err := e.cfg.repository(r)
	if err != nil {
		return err
	}
	fingerprint := sha256.Sum256([]byte(repo))
	if r.RepositoryFingerprint != hex.EncodeToString(fingerprint[:]) {
		return errors.New("retained repository location mismatch")
	}
	if p.Backend == "local" && !(Recovery{Backend: "local", Repository: repo}).Outside(append(protected, e.cfg.State)...) {
		return errors.New("repository overlaps protected storage")
	}
	var marker repositoryMarker
	if err := readJSON(e.repoMarker(r), &marker); err != nil || marker.Deleted || marker.Server != r.Server || marker.Profile != r.Profile {
		return errors.New("retained repository marker unavailable")
	}
	key, err := e.cfg.key(r)
	if err != nil || !key.Prepared || !key.Finalized {
		return errors.New("committed retained server key required")
	}
	return nil
}
