package engine

import (
	"errors"
	"os"
)

type RepositoryState struct {
	Host        int    `json:"host"`
	Node        int    `json:"node"`
	Server      string `json:"server"`
	Profile     string `json:"profile"`
	Generation  uint64 `json:"generation"`
	Exists      bool   `json:"exists"`
	Initialized bool   `json:"initialized"`
}

// Prove local leaf absence without relying on Restic credentials or a failed
// network listing. Existing/inaccessible/corrupt paths are never deleted here.
func (e *Engine) RepositoryState(r Request) (RepositoryState, error) {
	state := RepositoryState{Host: r.Host, Node: r.Node, Server: r.Server, Profile: r.Profile, Generation: r.Generation}
	repo, p, err := e.cfg.baseRepository(r)
	if err != nil || p.Backend != "local" || !(Recovery{Backend: "local", Repository: repo}).Outside(r.Root, e.cfg.State) {
		return state, errors.New("local repository state unavailable")
	}
	e.mu.Lock()
	defer e.mu.Unlock()
	if e.busy[r.Server] || e.uncertain(r.Server) {
		return state, errors.New("server busy or unresolved")
	}
	if _, markerErr := os.Lstat(e.repoMarker(r)); markerErr == nil {
		state.Initialized = true
	} else if !os.IsNotExist(markerErr) {
		return state, errors.New("repository history state unavailable")
	}
	_, err = os.Lstat(repo)
	if err == nil {
		state.Exists = true
		return state, nil
	}
	if !os.IsNotExist(err) {
		return state, errors.New("repository absence cannot be verified")
	}
	return state, nil
}
