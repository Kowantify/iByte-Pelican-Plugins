package engine

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// The journal survives a Panel transaction rollback. Legacy credentials remain
// usable until Panel acknowledges that the replacement is durably escrowed.
type serverKey struct {
	Password   string `json:"password_base64"`
	Previous   string `json:"previous_base64,omitempty"`
	PreviousID string `json:"previous_key_id,omitempty"`
	Prepared   bool   `json:"prepared"`
	Finalized  bool   `json:"finalized"`
}

func (c Config) keyPath(r Request) string {
	h := sha256.Sum256([]byte(fmtIdentity(r)))
	return filepath.Join(c.State, "server-key-"+hex.EncodeToString(h[:])+".json")
}

func fmtIdentity(r Request) string {
	// Include the entire immutable repository identity, including node and host.
	identity := fmt.Sprintf("%d/%d/%s/%s", r.Host, r.Node, r.Profile, r.Server)
	if r.Generation > 0 {
		identity += fmt.Sprintf("/generation-%d", r.Generation)
	}
	return identity
}

func (c Config) key(r Request) (serverKey, error) {
	var k serverKey
	b, err := privateFile(c.keyPath(r))
	if err != nil {
		if _, statErr := os.Lstat(c.keyPath(r)); os.IsNotExist(statErr) {
			return k, os.ErrNotExist
		}
		return k, err
	}
	if json.Unmarshal(b, &k) != nil {
		return k, errors.New("invalid server key journal")
	}
	b, err = base64.StdEncoding.DecodeString(k.Password)
	if err != nil || len(b) != 64 {
		return k, errors.New("invalid server credential")
	}
	return k, nil
}

func (c Config) keyPasswordPath(r Request) string { return c.keyPath(r) + ".password" }

func (e *Engine) provisionKey(r Request) error {
	if _, err := e.cfg.key(r); err == nil {
		return nil
	} else if !os.IsNotExist(err) {
		return err
	}
	var raw [32]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return err
	}
	password := []byte(hex.EncodeToString(raw[:]))
	path := e.cfg.keyPasswordPath(r)
	if err := noSymlink(path); err != nil {
		return err
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return err
	}
	_, writeErr := f.Write(password)
	syncErr := f.Sync()
	closeErr := f.Close()
	if writeErr != nil {
		return writeErr
	}
	if syncErr != nil {
		return syncErr
	}
	if closeErr != nil {
		return closeErr
	}
	return writeJSON(e.cfg.keyPath(r), serverKey{Password: base64.StdEncoding.EncodeToString(password), Prepared: true, Finalized: true})
}

func (e *Engine) keyList(ctx context.Context, r Request, repo string, p Profile) ([]struct {
	ID      string `json:"id"`
	Current bool   `json:"current"`
}, error) {
	var keys []struct {
		ID      string `json:"id"`
		Current bool   `json:"current"`
	}
	b, err := e.runProfile(ctx, r, repo, p, "key", "list", "--json")
	if err == nil {
		err = json.Unmarshal(b, &keys)
	}
	return keys, err
}

// PrepareServerKey is idempotent and never initializes or erases an existing repo.
// receipt is empty for prepare, or SHA256 of the escrowed password for finalize.
func (e *Engine) PrepareServerKey(ctx context.Context, r Request, receipt string) error {
	if r.Root != "" && !(Recovery{Backend: "local", Repository: e.cfg.State}).Outside(r.Root) {
		return errors.New("server key state overlaps game data")
	}
	repo, p, err := e.cfg.baseRepository(r)
	if err != nil {
		return err
	}
	e.mu.Lock()
	if e.busy[r.Server] || e.uncertain(r.Server) {
		e.mu.Unlock()
		return errors.New("server busy or unresolved")
	}
	select {
	case e.slots <- struct{}{}:
	default:
		e.mu.Unlock()
		return errors.New("node busy")
	}
	e.busy[r.Server] = true
	e.mu.Unlock()
	defer func() { e.mu.Lock(); delete(e.busy, r.Server); e.mu.Unlock(); <-e.slots }()
	if r.PreviousGeneration != nil {
		if p.Backend != "local" || *r.PreviousGeneration >= 1000000000 || r.Generation != *r.PreviousGeneration+1 {
			return errors.New("invalid repository generation transition")
		}
		previous := r
		previous.Generation = *r.PreviousGeneration
		oldRepo, _, err := e.cfg.baseRepository(previous)
		if err != nil {
			return err
		}
		if _, err := os.Lstat(oldRepo); !os.IsNotExist(err) {
			return errors.New("previous repository still exists or absence cannot be verified")
		}
	}
	k, err := e.cfg.key(r)
	if os.IsNotExist(err) {
		if receipt != "" {
			return errors.New("server key not prepared")
		}
		var raw [32]byte
		if _, err = rand.Read(raw[:]); err != nil {
			return err
		}
		k.Password = base64.StdEncoding.EncodeToString([]byte(hex.EncodeToString(raw[:])))
		_, markerErr := os.Stat(e.repoMarker(r))
		if markerErr == nil {
			keys, err := e.keyList(ctx, r, repo, p)
			if err != nil {
				return err
			}
			for _, key := range keys {
				if key.Current {
					k.PreviousID = key.ID
				}
			}
			if !snapshotID.MatchString(k.PreviousID) {
				return errors.New("legacy key identity unavailable")
			}
			previous, err := privateFile(p.PasswordFile)
			if err != nil {
				return err
			}
			k.Previous = base64.StdEncoding.EncodeToString(previous)
		} else if !os.IsNotExist(markerErr) {
			return markerErr
		} else if p.Backend == "local" {
			if _, err := os.Lstat(repo); !os.IsNotExist(err) {
				return errors.New("unrecognized existing repository; explicit provisioning required")
			}
			k.Prepared, k.Finalized = true, true
		} else {
			return errors.New("remote repository requires explicit provisioning")
		}
		if err = writeJSON(e.cfg.keyPath(r), k); err != nil {
			return err
		}
	} else if err != nil {
		return err
	}
	password, _ := base64.StdEncoding.DecodeString(k.Password)
	path := e.cfg.keyPasswordPath(r)
	if err = noSymlink(path); err != nil {
		return err
	}
	if existing, err := privateFile(path); err == nil {
		if string(existing) != string(password) {
			return errors.New("server key file mismatch")
		}
	} else if _, statErr := os.Lstat(path); os.IsNotExist(statErr) {
		f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if err != nil {
			return err
		}
		_, writeErr := f.Write(password)
		syncErr := f.Sync()
		closeErr := f.Close()
		if writeErr != nil {
			return writeErr
		}
		if syncErr != nil {
			return syncErr
		}
		if closeErr != nil {
			return closeErr
		}
	} else {
		return err
	}
	unique := p
	unique.PasswordFile = path
	if !k.Prepared {
		// Retry after a crash between key-add and journal persistence by proving
		// the new credential first; never create another wrapper unnecessarily.
		if _, err = e.keyList(ctx, r, repo, unique); err != nil {
			if _, err = e.runProfile(ctx, r, repo, p, "key", "add", "--new-password-file", path, "--user", "ibyte-restic", "--host", r.Server); err != nil {
				return err
			}
		}
		if _, err = e.keyList(ctx, r, repo, unique); err != nil {
			return err
		}
		k.Prepared = true
		if err = writeJSON(e.cfg.keyPath(r), k); err != nil {
			return err
		}
	}
	if receipt != "" {
		digest := sha256.Sum256(password)
		if receipt != hex.EncodeToString(digest[:]) {
			return errors.New("escrow receipt mismatch")
		}
		if !k.Finalized {
			keys, err := e.keyList(ctx, r, repo, unique)
			if err != nil {
				return err
			}
			for _, key := range keys {
				if key.ID == k.PreviousID {
					if _, err = e.runProfile(ctx, r, repo, unique, "key", "remove", k.PreviousID); err != nil {
						return err
					}
				}
			}
			k.Finalized = true
			if err = writeJSON(e.cfg.keyPath(r), k); err != nil {
				return err
			}
		}
	}
	return nil
}
