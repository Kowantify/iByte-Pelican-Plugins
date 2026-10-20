package engine

import (
	"context"
	"encoding/json"
	"errors"
	"io/fs"
	"math"
	"path/filepath"
)

// These are historical snapshot-creation counters, not exclusive ownership of
// shared blobs or the number of bytes that forgetting a snapshot would reclaim.
type SnapshotStorage struct {
	LogicalBytes int64 `json:"total_bytes_processed"`
	AddedBytes   int64 `json:"data_added"`
	PackedBytes  int64 `json:"data_added_packed"`
}

type RepositoryStats struct {
	StoredBytes       int64   `json:"total_size"`
	UncompressedBytes int64   `json:"total_uncompressed_size"`
	LogicalBytes      int64   `json:"logical_bytes"`
	SnapshotCount     int64   `json:"snapshots_count"`
	CompressionRatio  float64 `json:"compression_ratio"`
	CompressionSaving float64 `json:"compression_space_saving"`
	// Local only: sum of regular repository file lengths, including metadata and
	// unreferenced packs. This is not allocated filesystem blocks or S3 billing.
	FileBytes *int64                     `json:"repository_file_bytes,omitempty"`
	Snapshots map[string]SnapshotStorage `json:"snapshots"`
}

// A verified snapshot remains successful if a subsequent accounting scan fails.
// Persist the warning; Panel maintenance retries statistics automatically.
func (e *Engine) captureStatistics(ctx context.Context, r Request, j *Job) {
	stats, err := e.repositoryStats(ctx, r)
	if err != nil {
		j.MaintenanceError = "repository statistics unavailable; automatic retry required"
		return
	}
	j.Repository = stats
}

func (e *Engine) repositoryStats(ctx context.Context, r Request) (*RepositoryStats, error) {
	b, err := e.run(ctx, r, "stats", "--json", "--mode", "raw-data")
	if err != nil {
		return nil, err
	}
	var s RepositoryStats
	if err := json.Unmarshal(b, &s); err != nil {
		return nil, err
	}
	b, err = e.run(ctx, r, "stats", "--json", "--mode", "restore-size")
	if err != nil {
		return nil, err
	}
	var logical struct {
		Bytes int64 `json:"total_size"`
	}
	if err := json.Unmarshal(b, &logical); err != nil {
		return nil, err
	}
	s.LogicalBytes = logical.Bytes
	s.Snapshots = map[string]SnapshotStorage{}
	list, err := e.snapshots(ctx, r)
	if err != nil {
		return nil, err
	}
	for _, sn := range list {
		if sn.Summary == nil || !contains(sn.Tags, "server:"+r.Server) || len(sn.Paths) != 1 || sn.Paths[0] != r.Root {
			continue
		}
		for _, tag := range sn.Tags {
			if len(tag) == 43 && tag[:7] == "backup:" && uuid.MatchString(tag[7:]) {
				if _, exists := s.Snapshots[tag[7:]]; exists {
					return nil, errors.New("duplicate backup summary")
				}
				s.Snapshots[tag[7:]] = *sn.Summary
			}
		}
	}
	repo, p, err := e.cfg.repository(r)
	if err != nil {
		return nil, err
	}
	if p.Backend == "local" {
		var total int64
		err = filepath.WalkDir(repo, func(path string, entry fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			if err := ctx.Err(); err != nil {
				return err
			}
			if entry.Type()&fs.ModeSymlink != 0 {
				return errors.New("repository contains symlink")
			}
			if entry.IsDir() {
				return nil
			}
			info, err := entry.Info()
			if err != nil {
				return err
			}
			if !info.Mode().IsRegular() || info.Size() < 0 || total > math.MaxInt64-info.Size() {
				return errors.New("invalid repository file")
			}
			total += info.Size()
			return nil
		})
		if err != nil {
			return nil, err
		}
		s.FileBytes = &total
	}
	return &s, nil
}
