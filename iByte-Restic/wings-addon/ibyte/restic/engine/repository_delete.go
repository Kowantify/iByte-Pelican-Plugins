package engine

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
)

type RepositoryPlan struct {
	Host       int               `json:"host"`
	Node       int               `json:"node"`
	Server     string            `json:"server"`
	Profile    string            `json:"profile"`
	Repository string            `json:"repository"`
	Snapshots  map[string]string `json:"snapshots"`
	Deleted    bool              `json:"deleted"`
}

type repositoryMarker struct {
	Server  string `json:"server"`
	Profile string `json:"profile"`
	Deleted bool   `json:"deleted"`
}

func (e *Engine) RepositoryPlan(ctx context.Context, r Request) (RepositoryPlan, error) {
	if _, _, err := e.cfg.repository(r); err != nil {
		return RepositoryPlan{}, err
	}
	e.mu.Lock()
	if e.busy[r.Server] || e.uncertain(r.Server) {
		e.mu.Unlock()
		return RepositoryPlan{}, errors.New("server busy or unresolved")
	}
	select {
	case e.slots <- struct{}{}:
	default:
		e.mu.Unlock()
		return RepositoryPlan{}, errors.New("node busy")
	}
	e.busy[r.Server] = true
	e.mu.Unlock()
	defer func() { e.mu.Lock(); delete(e.busy, r.Server); e.mu.Unlock(); <-e.slots }()
	return e.repositoryPlan(ctx, r)
}

func (e *Engine) repositoryPlan(ctx context.Context, r Request) (RepositoryPlan, error) {
	repo, p, err := e.cfg.repository(r)
	out := RepositoryPlan{Host: r.Host, Node: r.Node, Server: r.Server, Profile: r.Profile, Repository: repo, Snapshots: map[string]string{}}
	if err != nil {
		return out, err
	}
	if p.Backend != "local" {
		return out, errors.New("repository deletion supports local backend only")
	}
	if !(Recovery{Backend: "local", Repository: repo}).Outside(r.Root, e.cfg.State) {
		return out, errors.New("repository overlaps protected data")
	}
	var marker repositoryMarker
	if err := readJSON(e.repoMarker(r), &marker); err != nil || marker.Server != r.Server || marker.Profile != r.Profile {
		return out, errors.New("repository ownership marker unavailable")
	}
	if marker.Deleted {
		if _, err := os.Lstat(repo); !os.IsNotExist(err) {
			return out, errors.New("retired repository path exists; explicit reconciliation required")
		}
		out.Deleted = true
		return out, nil
	}
	list, err := e.snapshots(ctx, r)
	if err != nil {
		return out, err
	}
	for _, s := range list {
		backup := ""
		for _, tag := range s.Tags {
			if strings.HasPrefix(tag, "backup:") {
				if backup != "" {
					return out, errors.New("ambiguous snapshot tags")
				}
				backup = strings.TrimPrefix(tag, "backup:")
			}
		}
		if !uuid.MatchString(backup) {
			return out, errors.New("unrecognized repository snapshot")
		}
		owned := r
		owned.Backup = backup
		m, err := e.Mapping(owned)
		if err != nil || m.Deleted || m.Snapshot != s.ID || len(s.Paths) != 1 || s.Paths[0] != r.Root || !contains(s.Tags, "server:"+r.Server) {
			return out, errors.New("unrecognized repository snapshot mapping")
		}
		if _, exists := out.Snapshots[backup]; exists {
			return out, errors.New("duplicate snapshot mapping")
		}
		out.Snapshots[backup] = s.ID
	}
	return out, nil
}

// Only an empty, addon-owned local leaf may be removed. Panel must first forget
// its explicit unlocked snapshots; unknown snapshots prevent any recursive wipe.
func (e *Engine) deleteEmptyRepository(ctx context.Context, r Request) error {
	plan, err := e.repositoryPlan(ctx, r)
	if err != nil {
		return err
	}
	fingerprint := sha256.Sum256([]byte(plan.Repository))
	if r.RepositoryFingerprint != hex.EncodeToString(fingerprint[:]) {
		return errors.New("repository location changed since confirmation")
	}
	if plan.Deleted {
		return nil
	}
	if len(plan.Snapshots) != 0 {
		return errors.New("repository still contains snapshots")
	}
	if err := trustedPath(plan.Repository); err != nil {
		return err
	}
	if err := safeTree(plan.Repository); err != nil {
		return err
	}
	locks, err := os.ReadDir(filepath.Join(plan.Repository, "locks"))
	if err != nil || len(locks) != 0 {
		return errors.New("repository lock state unavailable or occupied")
	}
	// Retire BEFORE filesystem mutation. A crash/partial removal never allows a
	// fresh automatic init or a second blind wipe of an uncertain repository.
	if err := writeJSON(e.repoMarker(r), repositoryMarker{Server: r.Server, Profile: r.Profile, Deleted: true}); err != nil {
		return err
	}
	if err := os.RemoveAll(plan.Repository); err != nil {
		return errors.New("repository removal incomplete; preserve state and reconcile")
	}
	if _, err := os.Lstat(plan.Repository); !os.IsNotExist(err) {
		return errors.New("repository removal could not be verified")
	}
	return nil
}
