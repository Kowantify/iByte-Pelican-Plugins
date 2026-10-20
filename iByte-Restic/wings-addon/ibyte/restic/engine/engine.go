package engine

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"
)

const Version = "3.0.0-preview.2"

type Request struct {
	ArchiveFormat         string   `json:"archive_format,omitempty"`
	Generation            uint64   `json:"generation,omitempty"`
	PreviousGeneration    *uint64  `json:"previous_generation,omitempty"`
	RepositoryFingerprint string   `json:"repository_fingerprint,omitempty"`
	EscrowReceipt         string   `json:"escrow_receipt,omitempty"`
	Backend               string   `json:"backend"`
	RequestID             string   `json:"request_id"`
	Host                  int      `json:"host"`
	Node                  int      `json:"node"`
	Profile               string   `json:"profile"`
	Server                string   `json:"server"`
	Backup                string   `json:"backup"`
	Operation             string   `json:"operation"`
	Ignore                []string `json:"ignore,omitempty"`
	Root                  string   `json:"-"`
}
type Job struct {
	Generation       uint64           `json:"generation,omitempty"`
	RequestID        string           `json:"request_id"`
	ID               string           `json:"id"`
	Server           string           `json:"server"`
	Backup           string           `json:"backup"`
	Host             int              `json:"host"`
	Node             int              `json:"node"`
	Profile          string           `json:"profile"`
	Operation        string           `json:"operation"`
	Status           string           `json:"status"`
	Snapshot         string           `json:"snapshot,omitempty"`
	Bytes            int64            `json:"bytes"`
	Storage          *SnapshotStorage `json:"storage,omitempty"`
	Repository       *RepositoryStats `json:"repository,omitempty"`
	MaintenanceError string           `json:"maintenance_error,omitempty"`
	Error            string           `json:"error,omitempty"`
	Updated          time.Time        `json:"updated"`
}
type Mapping struct {
	Generation uint64 `json:"generation,omitempty"`
	Host       int    `json:"host"`
	Node       int    `json:"node"`
	Profile    string `json:"profile"`
	Server     string `json:"server"`
	Backup     string `json:"backup"`
	Snapshot   string `json:"snapshot"`
	Bytes      int64  `json:"bytes"`
	Deleted    bool   `json:"deleted"`
}
type Engine struct {
	cfg        Config
	mu         sync.Mutex
	busy       map[string]bool
	quarantine map[string]Job
	slots      chan struct{}
	// FailCommit is only set by fault-injection tests. Never configured over HTTP.
	FailCommit  func(int) error
	FailPersist func(Job) error // fault injection only; no HTTP/config exposure
}

func (e *Engine) ProfileBackends() map[string]string {
	out := map[string]string{}
	for name, p := range e.cfg.Profiles {
		out[name] = p.Backend
	}
	return out
}

// RecoveryServers is used before the daemon router starts serving requests.
// Unknown work quarantines only the affected known servers, never all Wings hosts.
func (e *Engine) RecoveryServers() []string {
	e.mu.Lock()
	defer e.mu.Unlock()
	set := map[string]bool{}
	for _, j := range e.quarantine {
		set[j.Server] = true
	}
	files, _ := filepath.Glob(filepath.Join(e.cfg.State, "job-*.json"))
	for _, p := range files {
		var j Job
		if readJSON(p, &j) == nil && j.Status == "unknown" && uuid.MatchString(j.Server) {
			set[j.Server] = true
		}
	}
	files, _ = filepath.Glob(filepath.Join(e.cfg.State, "restore-*.json"))
	for _, p := range files {
		server := strings.TrimSuffix(strings.TrimPrefix(filepath.Base(p), "restore-"), ".json")
		if uuid.MatchString(server) {
			set[server] = true
		}
	}
	out := []string{}
	for server := range set {
		out = append(out, server)
	}
	return out
}

func New(c Config) (*Engine, error) {
	if err := c.Validate(); err != nil {
		return nil, err
	}
	if err := noSymlink(c.State); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(c.State, 0700); err != nil {
		return nil, err
	}
	e := &Engine{cfg: c, busy: map[string]bool{}, quarantine: map[string]Job{}, slots: make(chan struct{}, 2)}
	// An interrupted destructive job is never silently replayed or marked successful.
	files, err := filepath.Glob(filepath.Join(c.State, "job-*.json"))
	if err != nil {
		return nil, err
	}
	for _, file := range files {
		var j Job
		if err := readJSON(file, &j); err != nil {
			return nil, err
		}
		if j.Status == "running" {
			j.Status = "unknown"
			j.Error = "daemon interrupted; operator reconciliation required"
			if err := e.saveJob(&j); err != nil {
				return nil, err
			}
		}
	}
	return e, nil
}
func id(r Request) string {
	h := sha256.Sum256([]byte(fmtIdentity(r) + "/" + r.Backup + "/" + r.Operation + "/" + r.RequestID))
	return hex.EncodeToString(h[:])
}
func (e *Engine) jobPath(i string) string { return filepath.Join(e.cfg.State, "job-"+i+".json") }
func (e *Engine) mapPath(r Request) string {
	h := sha256.Sum256([]byte(fmtIdentity(r) + "/" + r.Backup))
	return filepath.Join(e.cfg.State, "map-"+hex.EncodeToString(h[:])+".json")
}
func (e *Engine) repoMarker(r Request) string {
	h := sha256.Sum256([]byte(fmtIdentity(r)))
	return filepath.Join(e.cfg.State, "repo-"+hex.EncodeToString(h[:])+".json")
}

// Provision explicitly initializes a NEW repository, never probes failure as absence.
// Restic init itself refuses an existing repository. The helper exposes this only to root.
func (e *Engine) Provision(ctx context.Context, r Request) error {
	if _, _, err := e.cfg.repository(r); err != nil {
		return err
	}
	if _, err := os.Stat(e.repoMarker(r)); err == nil {
		return errors.New("repository already provisioned")
	}
	if err := e.provisionKey(r); err != nil {
		return err
	}
	if _, err := e.run(ctx, r, "init"); err != nil {
		return err
	}
	return writeJSON(e.repoMarker(r), map[string]string{"server": r.Server, "profile": r.Profile})
}
func writeJSON(path string, v any) error {
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	f, err := os.CreateTemp(filepath.Dir(path), ".state-")
	if err != nil {
		return err
	}
	temp := f.Name()
	defer os.Remove(temp)
	if err = f.Chmod(0600); err == nil {
		_, err = f.Write(b)
	}
	if err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err != nil {
		return err
	}
	if closeErr != nil {
		return closeErr
	}
	var renameErr error
	for attempt := 0; attempt < 10; attempt++ {
		renameErr = os.Rename(temp, path)
		if renameErr == nil {
			break
		}
		if runtime.GOOS != "windows" {
			return renameErr
		}
		time.Sleep(10 * time.Millisecond)
	}
	if renameErr != nil {
		return renameErr
	}
	return syncDir(path)
}
func readJSON(path string, v any) error {
	if err := noSymlink(path); err != nil {
		return err
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	return json.Unmarshal(b, v)
}
func (e *Engine) saveJob(j *Job) error {
	j.Updated = time.Now().UTC()
	if e.FailPersist != nil {
		if err := e.FailPersist(*j); err != nil {
			return err
		}
	}
	return writeJSON(e.jobPath(j.ID), j)
}
func (e *Engine) Status(r Request) (Job, error) {
	var j Job
	if err := e.validateRepositoryRequest(r); err != nil {
		return j, err
	}
	e.mu.Lock()
	unknown, ok := e.quarantine[id(r)]
	e.mu.Unlock()
	if ok {
		return unknown, nil
	}
	err := readJSON(e.jobPath(id(r)), &j)
	return j, err
}

func (e *Engine) validateRepositoryRequest(r Request) error {
	if r.Operation == "repo-force-delete" {
		_, _, err := e.cfg.baseRepository(r)
		return err
	}
	_, _, err := e.cfg.repository(r)
	return err
}
func (e *Engine) Mapping(r Request) (Mapping, error) {
	var m Mapping
	err := readJSON(e.mapPath(r), &m)
	if err == nil && (m.Generation != r.Generation || m.Host != r.Host || m.Node != r.Node || m.Profile != r.Profile || m.Server != r.Server || m.Backup != r.Backup || !snapshotID.MatchString(m.Snapshot)) {
		return m, errors.New("mapping identity mismatch")
	}
	return m, err
}
func (e *Engine) Submit(ctx context.Context, r Request, guard func() error, finish func(Job)) (Job, error) {
	if (r.Operation == "repo-delete" || r.Operation == "repo-force-delete") && !uuid.MatchString(r.RequestID) {
		return Job{}, errors.New("repository deletion request UUID required")
	}
	if r.Operation == "create" {
		var marker repositoryMarker
		if readJSON(e.repoMarker(r), &marker) == nil && marker.Deleted {
			return Job{}, errors.New("repository retired; configure a new backup host for new repositories")
		}
		if !(Recovery{Backend: "local", Repository: e.cfg.State}).Outside(r.Root) {
			return Job{}, errors.New("private state overlaps game data")
		}
		if k, err := e.cfg.key(r); err != nil || !k.Prepared || !k.Finalized {
			return Job{}, errors.New("unique server key and committed recovery escrow required before backup")
		}
	}
	if r.RequestID != "" && !uuid.MatchString(r.RequestID) {
		return Job{}, errors.New("invalid request UUID")
	}
	if r.Operation == "restore" && r.RequestID == "" {
		return Job{}, errors.New("restore request UUID required")
	}
	if err := e.validateRepositoryRequest(r); err != nil {
		return Job{}, err
	}
	if r.Operation != "create" && r.Operation != "delete" && r.Operation != "restore" && r.Operation != "check" && r.Operation != "stats" && r.Operation != "prune" && r.Operation != "repo-delete" && r.Operation != "repo-force-delete" {
		return Job{}, errors.New("unsupported operation")
	}
	if len(r.Ignore) > 128 {
		return Job{}, errors.New("too many exclude patterns")
	}
	for _, s := range r.Ignore {
		if len(s) > 256 || strings.ContainsAny(s, "\x00\r\n") || strings.HasPrefix(s, "-") {
			return Job{}, errors.New("invalid exclude pattern")
		}
	}
	e.mu.Lock()
	defer e.mu.Unlock()
	if j, ok := e.quarantine[id(r)]; ok {
		return j, nil
	}
	var old Job
	if err := readJSON(e.jobPath(id(r)), &old); err == nil {
		if r.Operation != "repo-force-delete" || old.Status != "failed" {
			return old, nil
		}
	} else if !os.IsNotExist(err) {
		return Job{}, err
	}
	if e.busy[r.Server] {
		return Job{}, errors.New("server operation already running")
	}
	// Preserve uncertainty after restart, including restore commit journals.
	if r.Operation != "repo-force-delete" && e.uncertain(r.Server) {
		return Job{}, errors.New("server has interrupted work; reconcile first")
	}
	select {
	case e.slots <- struct{}{}:
	default:
		return Job{}, errors.New("node concurrency limit reached")
	}
	j := Job{Generation: r.Generation, RequestID: r.RequestID, ID: id(r), Server: r.Server, Backup: r.Backup, Host: r.Host, Node: r.Node, Profile: r.Profile, Operation: r.Operation, Status: "running"}
	if err := e.saveJob(&j); err != nil {
		<-e.slots
		return Job{}, err
	}
	e.busy[r.Server] = true
	accepted := j // copy before the goroutine can mutate completion fields
	go func() {
		defer func() { e.mu.Lock(); delete(e.busy, r.Server); e.mu.Unlock(); <-e.slots }()
		opctx, cancel := context.WithTimeout(ctx, 2*time.Hour)
		defer cancel()
		err := guard()
		if err == nil {
			err = e.execute(opctx, r, &j)
		}
		j.Status = "succeeded"
		if err != nil {
			j.Status = "failed"
			j.Error = err.Error()
		}
		if _, err := os.Stat(filepath.Join(e.cfg.State, "restore-"+r.Server+".json")); err == nil && r.Operation != "repo-force-delete" {
			j.Status = "unknown"
			j.Error = "restore journal retained; keep server stopped and reconcile"
		}
		if err := e.saveJob(&j); err != nil {
			j.Status = "unknown"
			j.Error = "cannot persist completion"
			e.mu.Lock()
			e.quarantine[j.ID] = j
			e.mu.Unlock()
		}
		if finish != nil {
			finish(j)
		}
	}()
	return accepted, nil
}
func (e *Engine) uncertain(server string) bool {
	for _, j := range e.quarantine {
		if j.Server == server {
			return true
		}
	}
	if _, err := os.Stat(filepath.Join(e.cfg.State, "restore-"+server+".json")); err == nil {
		return true
	}
	files, _ := filepath.Glob(filepath.Join(e.cfg.State, "job-*.json"))
	for _, p := range files {
		var j Job
		if readJSON(p, &j) != nil {
			return true
		}
		if j.Server == server && j.Status == "unknown" {
			return true
		}
	}
	return false
}

type limitedBuffer struct {
	bytes.Buffer
	limit int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	n := len(p)
	if b.Len()+n > b.limit {
		return 0, errors.New("Restic output limit exceeded")
	}
	return b.Buffer.Write(p)
}
func (e *Engine) run(ctx context.Context, r Request, args ...string) ([]byte, error) {
	repo, p, err := e.cfg.repository(r)
	if err != nil {
		return nil, err
	}
	return e.runProfile(ctx, r, repo, p, args...)
}
func (e *Engine) runProfile(ctx context.Context, r Request, repo string, p Profile, args ...string) ([]byte, error) {
	if _, err := privateFile(p.PasswordFile); err != nil {
		return nil, err
	}
	cmd := exec.CommandContext(ctx, e.cfg.Binary, append([]string{"--repo", repo, "--password-file", p.PasswordFile, "--no-cache"}, args...)...)
	cmd.WaitDelay = 5 * time.Second
	cmd.Dir = r.Root
	if r.Operation == "check" || r.Operation == "stats" || r.Operation == "prune" || r.Operation == "download" {
		cmd.Dir = e.cfg.State
	}
	cmd.Env = []string{"PATH=/usr/bin:/bin", "HOME=" + e.cfg.State, "TMPDIR=" + e.cfg.State, "TEMP=" + e.cfg.State, "TMP=" + e.cfg.State}
	if systemRoot := os.Getenv("SystemRoot"); systemRoot != "" {
		cmd.Env = append(cmd.Env, "SystemRoot="+systemRoot)
	}
	if p.Backend == "s3" {
		b, err := privateFile(p.CredentialsFile)
		if err != nil {
			return nil, err
		}
		var creds struct {
			Key    string `json:"key"`
			Secret string `json:"secret"`
		}
		if json.Unmarshal(b, &creds) != nil || creds.Key == "" || creds.Secret == "" {
			return nil, errors.New("invalid S3 credential file")
		}
		cmd.Env = append(cmd.Env, "AWS_ACCESS_KEY_ID="+creds.Key, "AWS_SECRET_ACCESS_KEY="+creds.Secret)
	}
	out := &limitedBuffer{limit: 8 << 20}
	stderr := &limitedBuffer{limit: 64 << 10}
	cmd.Stdout = out
	cmd.Stderr = stderr
	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("restic %s failed: %w (output redacted)", args[0], err)
	}
	return out.Bytes(), nil
}

type snapshot struct {
	ID      string           `json:"id"`
	Tags    []string         `json:"tags"`
	Paths   []string         `json:"paths"`
	Summary *SnapshotStorage `json:"summary,omitempty"`
}

func (e *Engine) snapshots(ctx context.Context, r Request) ([]snapshot, error) {
	b, err := e.run(ctx, r, "snapshots", "--json")
	if err != nil {
		return nil, err
	}
	var list []snapshot
	err = json.Unmarshal(b, &list)
	return list, err
}

var errSnapshotMissing = errors.New("mapped snapshot is absent")

func (e *Engine) ownedSnapshot(ctx context.Context, r Request, m Mapping) error {
	list, err := e.snapshots(ctx, r)
	if err != nil {
		return err
	}
	for _, s := range list {
		if s.ID == m.Snapshot {
			if contains(s.Tags, "server:"+r.Server) && contains(s.Tags, "backup:"+r.Backup) && len(s.Paths) == 1 && s.Paths[0] == r.Root {
				return nil
			}
			return errors.New("snapshot identity or original root mismatch")
		}
	}
	return errSnapshotMissing
}
func contains(xs []string, x string) bool {
	for _, v := range xs {
		if v == x {
			return true
		}
	}
	return false
}
func (e *Engine) execute(ctx context.Context, r Request, j *Job) error {
	switch r.Operation {
	case "repo-force-delete":
		return e.forceDeleteRepository(ctx, r)
	case "repo-delete":
		return e.deleteEmptyRepository(ctx, r)
	case "create":
		if err := safeTree(r.Root); err != nil {
			return err
		}
		repo, p, _ := e.cfg.repository(r)
		if p.Backend == "local" {
			// Initialize only a freshly created leaf. Never initialize or wipe an existing directory.
			if err := os.MkdirAll(filepath.Dir(repo), 0700); err != nil {
				return err
			}
			if err := os.Mkdir(repo, 0700); err == nil {
				if err = e.Provision(ctx, r); err != nil {
					return err
				}
			} else if !os.IsExist(err) {
				return err
			}
		} // Remote repositories require explicit offline provisioning; never infer absence from network errors.
		if _, err := os.Stat(e.repoMarker(r)); err != nil {
			return errors.New("repository lacks fresh provisioning marker")
		}
		if k, err := e.cfg.key(r); err != nil || !k.Prepared || !k.Finalized {
			return errors.New("unique server key requires committed Panel escrow before backup")
		}
		list, err := e.snapshots(ctx, r)
		if err != nil {
			return err
		}
		var found string
		for _, s := range list {
			if contains(s.Tags, "backup:"+r.Backup) && contains(s.Tags, "server:"+r.Server) {
				if found != "" {
					return errors.New("duplicate backup tag")
				}
				found = s.ID
			}
		}
		if found == "" {
			args := []string{"backup", "--json", "--tag", "server:" + r.Server, "--tag", "backup:" + r.Backup}
			if runtime.GOOS != "windows" {
				args = append(args, "--one-file-system")
			}
			for _, pattern := range r.Ignore {
				args = append(args, "--exclude", pattern)
			}
			args = append(args, "--", r.Root)
			b, err := e.run(ctx, r, args...)
			if err != nil {
				return err
			}
			for _, line := range bytes.Split(b, []byte("\n")) {
				var summary struct {
					Type   string `json:"message_type"`
					ID     string `json:"snapshot_id"`
					Bytes  int64  `json:"total_bytes_processed"`
					Added  int64  `json:"data_added"`
					Packed int64  `json:"data_added_packed"`
				}
				if json.Unmarshal(line, &summary) == nil && summary.Type == "summary" {
					found = summary.ID
					j.Bytes = summary.Bytes
					j.Storage = &SnapshotStorage{summary.Bytes, summary.Added, summary.Packed}
				}
			}
		}
		if !snapshotID.MatchString(found) {
			return errors.New("no verified snapshot")
		}
		j.Snapshot = found
		// Snapshot summaries are durable and recover accounting if backup completed
		// before this process persisted its mapping/job result.
		if j.Storage == nil {
			list, err = e.snapshots(ctx, r)
			if err != nil {
				return err
			}
			for _, s := range list {
				if s.ID == found {
					j.Storage = s.Summary
					break
				}
			}
			if j.Storage == nil {
				return errors.New("snapshot summary unavailable")
			}
			j.Bytes = j.Storage.LogicalBytes
		}
		m := Mapping{Generation: r.Generation, Host: r.Host, Node: r.Node, Profile: r.Profile, Server: r.Server, Backup: r.Backup, Snapshot: found, Bytes: j.Bytes}
		if err := e.ownedSnapshot(ctx, r, m); err != nil {
			return err
		}
		if err := writeJSON(e.mapPath(r), m); err != nil {
			return err
		}
		e.captureStatistics(ctx, r, j)
		return nil
	case "prune":
		// Never combine forget/keep flags with prune. Panel selects snapshots
		// using native locks/RBAC; this only removes unreferenced repository data.
		if _, err := os.Stat(e.repoMarker(r)); err != nil {
			return errors.New("repository lacks fresh provisioning marker")
		}
		if _, err := e.run(ctx, r, "prune", "--max-repack-size", "1G"); err != nil {
			return err
		}
		e.captureStatistics(ctx, r, j)
		return nil
	case "check":
		_, err := e.run(ctx, r, "check")
		return err
	case "stats":
		stats, err := e.repositoryStats(ctx, r)
		if err != nil {
			return err
		}
		j.Repository = stats
		j.Bytes = stats.StoredBytes
		return nil
	default:
		m, err := e.Mapping(r)
		if err != nil {
			return err
		}
		if m.Deleted {
			if r.Operation == "delete" {
				return nil
			}
			return errors.New("snapshot deleted")
		}
		if err = e.ownedSnapshot(ctx, r, m); err != nil && !(r.Operation == "delete" && errors.Is(err, errSnapshotMissing)) {
			return err
		}
		j.Snapshot = m.Snapshot
		j.Bytes = m.Bytes
		if r.Operation == "delete" {
			// A complete authenticated repository listing can prove this exact
			// mapped snapshot already absent. Network/password/mapping/root
			// errors are not absence, and no other snapshot is selected.
			if err == nil {
				if _, err = e.run(ctx, r, "forget", m.Snapshot); err != nil {
					return err
				}
			}
			m.Deleted = true
			return writeJSON(e.mapPath(r), m)
		}
		if r.Operation == "restore" {
			return e.restore(ctx, r, m)
		}
	}
	return errors.New("unsupported operation")
}

// Export writes a bounded tar stream with no daemon token or repository credential in its URL.
func (e *Engine) Export(ctx context.Context, r Request, w io.Writer) error {
	if r.ArchiveFormat != "" && r.ArchiveFormat != "tar" && r.ArchiveFormat != "tar.gz" {
		return errors.New("unsupported export format")
	}
	e.mu.Lock()
	if e.busy[r.Server] || e.uncertain(r.Server) {
		e.mu.Unlock()
		return errors.New("server busy")
	}
	select {
	case e.slots <- struct{}{}:
	default:
		e.mu.Unlock()
		return errors.New("node concurrency limit reached")
	}
	e.busy[r.Server] = true
	e.mu.Unlock()
	defer func() { e.mu.Lock(); delete(e.busy, r.Server); e.mu.Unlock(); <-e.slots }()
	m, err := e.Mapping(r)
	if err != nil || m.Deleted {
		return errors.New("snapshot unavailable")
	}
	if m.Bytes > e.cfg.MaxArchiveBytes {
		return errors.New("snapshot exceeds archive staging limit")
	}
	if err = e.ownedSnapshot(ctx, r, m); err != nil {
		return err
	}
	stage, err := os.MkdirTemp(e.cfg.State, ".ibyte-export-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	source, err := e.stage(ctx, r, m, stage)
	if err != nil {
		return err
	}
	output := &boundedWriter{writer: w, remaining: e.cfg.MaxArchiveBytes}
	if r.ArchiveFormat == "tar.gz" {
		compressed := gzip.NewWriter(output)
		err := archive(source, compressed, e.cfg.MaxArchiveBytes)
		closeErr := compressed.Close()
		if err != nil {
			return err
		}
		return closeErr
	}
	return archive(source, output, e.cfg.MaxArchiveBytes)
}
func (e *Engine) stage(ctx context.Context, r Request, m Mapping, target string) (string, error) {
	if err := freeSpace(target, m.Bytes); err != nil {
		return "", err
	}
	path := filepath.ToSlash(r.Root)
	if runtime.GOOS == "windows" {
		path = "/" + strings.ReplaceAll(path, ":", "")
	}
	if _, err := e.run(ctx, r, "restore", m.Snapshot+":"+path, "--target", target, "--verify"); err != nil {
		return "", err
	}
	// Exact snapshot subfolder restores only this server's content at the staging root.
	source := target
	if err := safeTree(source); err != nil {
		return "", err
	}
	return source, nil
}
func (e *Engine) restore(ctx context.Context, r Request, m Mapping) error {
	if err := safeTree(r.Root); err != nil {
		return err
	}
	stage, err := os.MkdirTemp(filepath.Dir(r.Root), ".ibyte-restore-")
	if err != nil {
		return err
	}
	// A retained journal owns these directories after commit starts. No deferred deletion then.
	source, err := e.stage(ctx, r, m, stage)
	if err != nil {
		os.RemoveAll(stage)
		return err
	}
	rollback, err := os.MkdirTemp(filepath.Dir(r.Root), ".ibyte-rollback-")
	if err != nil {
		os.RemoveAll(stage)
		return err
	}
	journal := filepath.Join(e.cfg.State, "restore-"+r.Server+".json")
	if err = writeJSON(journal, map[string]string{"root": r.Root, "stage": stage, "rollback": rollback, "backup": r.Backup}); err != nil {
		os.RemoveAll(stage)
		os.RemoveAll(rollback)
		return err
	}
	if err = e.commit(source, r.Root, rollback); err != nil {
		// commit rolls back on ordinary failure. A failed rollback keeps the journal and both trees.
		if strings.Contains(err.Error(), "ROLLBACK FAILED") {
			return err
		}
		if er := os.Remove(journal); er != nil {
			return er
		}
		os.RemoveAll(stage)
		os.RemoveAll(rollback)
		return err
	}
	if err = os.Remove(journal); err != nil {
		return err
	}
	os.RemoveAll(stage)
	// Successful rollback copy is retained for operator review; no automatic cleanup policy.
	return nil
}
func (e *Engine) commit(source, root, rollback string) error {
	previous, err := os.ReadDir(root)
	if err != nil {
		return err
	}
	next, err := os.ReadDir(source)
	if err != nil {
		return err
	}
	var movedOld, movedNew []string
	undo := func(cause error) error {
		for i := len(movedNew) - 1; i >= 0; i-- {
			if err := os.Rename(filepath.Join(root, movedNew[i]), filepath.Join(source, movedNew[i])); err != nil {
				return fmt.Errorf("ROLLBACK FAILED: %w", cause)
			}
		}
		for i := len(movedOld) - 1; i >= 0; i-- {
			if err := os.Rename(filepath.Join(rollback, movedOld[i]), filepath.Join(root, movedOld[i])); err != nil {
				return fmt.Errorf("ROLLBACK FAILED: %w", cause)
			}
		}
		return cause
	}
	for _, d := range previous {
		if err = os.Rename(filepath.Join(root, d.Name()), filepath.Join(rollback, d.Name())); err != nil {
			return undo(err)
		}
		movedOld = append(movedOld, d.Name())
	}
	for i, d := range next {
		if e.FailCommit != nil {
			if err = e.FailCommit(i); err != nil {
				return undo(err)
			}
		}
		if err = os.Rename(filepath.Join(source, d.Name()), filepath.Join(root, d.Name())); err != nil {
			return undo(err)
		}
		movedNew = append(movedNew, d.Name())
	}
	return nil
}
