package engine

import (
	"context"
	"errors"
)

// VerifySnapshot proves the exact durable mapping still names an owned snapshot.
// Never infer presence from a Panel row or a generic repository health result.
func (e *Engine) VerifySnapshot(ctx context.Context, r Request) (Mapping, error) {
	if _, _, err := e.cfg.repository(r); err != nil {
		return Mapping{}, err
	}
	e.mu.Lock()
	if e.busy[r.Server] || e.uncertain(r.Server) {
		e.mu.Unlock()
		return Mapping{}, errors.New("server busy or unresolved")
	}
	select {
	case e.slots <- struct{}{}:
	default:
		e.mu.Unlock()
		return Mapping{}, errors.New("node busy")
	}
	e.busy[r.Server] = true
	e.mu.Unlock()
	defer func() { e.mu.Lock(); delete(e.busy, r.Server); e.mu.Unlock(); <-e.slots }()
	m, err := e.Mapping(r)
	if err != nil {
		return m, err
	}
	if m.Deleted {
		return m, errors.New("snapshot already forgotten")
	}
	return m, e.ownedSnapshot(ctx, r, m)
}
