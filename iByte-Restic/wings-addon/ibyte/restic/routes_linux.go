//go:build linux

package restic

import (
	"context"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/pelican/wings/config"
	"github.com/pelican/wings/environment"
	"github.com/pelican/wings/ibyte/restic/engine"
	"github.com/pelican/wings/router/middleware"
	"github.com/pelican/wings/server"
)

var once sync.Once
var worker *engine.Engine
var setupError error

// Server operations retain ServerExists. Root repository cleanup also has a
// daemon-authenticated group so retained repositories outlive deleted servers.
func Register(group *gin.RouterGroup, manager *server.Manager, admin *gin.RouterGroup) {
	if e, err := load(); err == nil {
		for _, id := range e.RecoveryServers() {
			if s, ok := manager.Get(id); ok {
				s.SetRestoring(true)
			}
		}
	}
	group.GET("/readiness", readiness)
	group.POST("/jobs", submit)
	group.POST("/status", status)
	group.POST("/export", export)
	group.POST("/recovery", recovery)
	group.POST("/server-key", serverKey)
	group.POST("/snapshot-proof", snapshotProof)
	group.POST("/repository-plan", repositoryPlan)
	group.POST("/repository-state", repositoryState)
	admin.GET("/readiness", readiness)
	admin.POST("/repository-delete", forceRepositoryDelete)
	admin.POST("/repository-delete/status", forceRepositoryDelete)
	admin.POST("/repository-maintenance", retainedMaintenance)
	admin.POST("/repository-maintenance/status", retainedMaintenance)
	admin.POST("/repository-export", retainedExport)
}
func load() (*engine.Engine, error) {
	once.Do(func() {
		c, err := engine.LoadConfig("/etc/pelican/ibyte-restic.json")
		if err != nil {
			setupError = err
			return
		}
		worker, setupError = engine.New(c)
	})
	return worker, setupError
}
func readiness(c *gin.Context) {
	e, err := load()
	if err != nil {
		c.JSON(503, gin.H{"version": engine.Version, "ready": false, "error": "node profile configuration unavailable or invalid"})
		return
	}
	c.JSON(200, gin.H{"version": engine.Version, "protocol": 1, "ready": true, "restore": "staging-only", "retained_maintenance": "v1", "retained_export": "v1", "recovery_vault": true, "repository_delete": "empty-local-v1", "force_repository_delete": "local-v1", "repository_generations": "local-v1", "profiles": e.ProfileBackends()})
}

func retainedExport(c *gin.Context) {
	var r engine.Request
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, 4096)
	if c.ShouldBindJSON(&r) != nil {
		c.AbortWithStatusJSON(400, gin.H{"error": "invalid export request"})
		return
	}
	r.Root = filepath.Join(config.Get().System.Data, r.Server)
	e, err := load()
	if err != nil || e.ValidateRetainedExport(r, config.Get().System.Data, config.Get().System.BackupDirectory) != nil {
		c.JSON(409, gin.H{"error": "retained export identity or state refused"})
		return
	}
	ctx, cancel := context.WithTimeout(c.Request.Context(), 30*time.Minute)
	defer cancel()
	f, err := os.CreateTemp("", "ibyte-retained-export-*")
	if err != nil {
		c.Status(507)
		return
	}
	defer os.Remove(f.Name())
	defer f.Close()
	if err = e.Export(ctx, r, f); err != nil {
		c.JSON(409, gin.H{"error": "retained export failed; snapshot unavailable or configured limits exceeded"})
		return
	}
	if err = f.Close(); err != nil {
		c.Status(500)
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.FileAttachment(f.Name(), r.Backup+"."+r.ArchiveFormat)
}

func retainedMaintenance(c *gin.Context) {
	var r engine.Request
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, 4096)
	if c.ShouldBindJSON(&r) != nil {
		c.AbortWithStatusJSON(400, gin.H{"error": "invalid maintenance request"})
		return
	}
	r.Root = filepath.Join(config.Get().System.Data, r.Server)
	e, err := load()
	if err != nil {
		c.JSON(503, gin.H{"error": "Restic configuration unavailable"})
		return
	}
	guard := func() error {
		return e.ValidateMaintenance(r, config.Get().System.Data, config.Get().System.BackupDirectory)
	}
	if guard() != nil {
		c.JSON(409, gin.H{"error": "retained repository identity or operation refused"})
		return
	}
	var job engine.Job
	if c.FullPath() == "/api/restic/repository-maintenance/status" {
		job, err = e.Status(r)
	} else {
		job, err = e.Submit(context.Background(), r, guard, nil)
	}
	if err != nil {
		c.JSON(409, gin.H{"error": "retained maintenance unavailable; inspect protected node state"})
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.JSON(200, job)
}

func repositoryState(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	state, err := e.RepositoryState(r)
	if err != nil {
		c.JSON(409, gin.H{"error": "local repository absence cannot be verified"})
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.JSON(200, state)
}

func forceRepositoryDelete(c *gin.Context) {
	var r engine.Request
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, 4096)
	if c.ShouldBindJSON(&r) != nil || r.Operation != "repo-force-delete" {
		c.AbortWithStatusJSON(400, gin.H{"error": "invalid repository deletion request"})
		return
	}
	// No browser path or deleted server lookup. Identity is checked by the engine
	// against configured node/profile and the escrowed location fingerprint.
	r.Root = filepath.Join(config.Get().System.Data, r.Server)
	e, err := load()
	if err != nil {
		c.JSON(503, gin.H{"error": "Restic configuration unavailable"})
		return
	}
	var job engine.Job
	if c.FullPath() == "/api/restic/repository-delete/status" {
		job, err = e.Status(r)
	} else {
		job, err = e.Submit(context.Background(), r, func() error {
			// Protect all native game and backup roots, not only this UUID leaf.
			return e.ValidateForceDeletion(r, config.Get().System.Data, config.Get().System.BackupDirectory)
		}, nil)
	}
	if err != nil {
		c.JSON(409, gin.H{"error": "repository deletion refused; node work active or identity invalid"})
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.JSON(200, job)
}
func request(c *gin.Context) (*engine.Engine, engine.Request, bool) {
	var r engine.Request
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, 32768)
	if err := c.ShouldBindJSON(&r); err != nil {
		c.AbortWithStatusJSON(400, gin.H{"error": "invalid request"})
		return nil, r, false
	}
	s := middleware.ExtractServer(c)
	if r.Server != s.ID() {
		c.AbortWithStatusJSON(403, gin.H{"error": "server identity mismatch"})
		return nil, r, false
	}
	r.Root = s.Filesystem().Path()
	expected := filepath.Join(config.Get().System.Data, s.ID())
	if r.Root != expected {
		c.AbortWithStatusJSON(409, gin.H{"error": "custom server volume unsupported"})
		return nil, r, false
	}
	if len(s.Config().Mounts) > 0 {
		c.AbortWithStatusJSON(409, gin.H{"error": "mounted servers unsupported in preview"})
		return nil, r, false
	}
	e, err := load()
	if err != nil {
		c.AbortWithStatusJSON(503, gin.H{"error": "Restic configuration unavailable"})
		return nil, r, false
	}
	return e, r, true
}
func status(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	j, err := e.Status(r)
	if err != nil {
		c.JSON(404, gin.H{"error": "job unavailable"})
		return
	}
	c.JSON(200, j)
}
func recovery(c *gin.Context) {
	c.Header("Cache-Control", "no-store, private")
	c.Header("Pragma", "no-cache")
	e, r, ok := request(c)
	if !ok {
		return
	}
	info, err := e.Recovery(r)
	if err != nil || !info.Outside(config.Get().System.Data, config.Get().System.BackupDirectory) {
		c.AbortWithStatusJSON(409, gin.H{"error": "recovery escrow unavailable"})
		return
	}
	// Secret-bearing exception explicitly requested by the operator: private
	// authenticated TLS transport to encrypted Panel escrow, never job JSON.
	c.JSON(200, info)
}
func serverKey(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	ctx, cancel := context.WithTimeout(c.Request.Context(), 25*time.Second)
	defer cancel()
	if err := e.PrepareServerKey(ctx, r, r.EscrowReceipt); err != nil {
		c.JSON(409, gin.H{"error": "server key preparation unavailable; preserve escrow and reconcile"})
		return
	}
	c.JSON(200, gin.H{"prepared": true})
}
func snapshotProof(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	ctx, cancel := context.WithTimeout(c.Request.Context(), 25*time.Second)
	defer cancel()
	m, err := e.VerifySnapshot(ctx, r)
	if err != nil {
		c.JSON(409, gin.H{"error": "retained snapshot identity unavailable"})
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.JSON(200, m)
}
func repositoryPlan(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	ctx, cancel := context.WithTimeout(c.Request.Context(), 25*time.Second)
	defer cancel()
	plan, err := e.RepositoryPlan(ctx, r)
	if err != nil {
		c.JSON(409, gin.H{"error": "local repository plan unavailable; preserve backups and reconcile"})
		return
	}
	c.Header("Cache-Control", "no-store, private")
	c.JSON(200, plan)
}
func submit(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	s := middleware.ExtractServer(c)
	if s.IsInProtectedState() && r.Operation != "restore" {
		c.JSON(409, gin.H{"error": "server protected"})
		return
	}
	// Every restore requires explicit native truncate confirmation and a stopped server.
	// The plugin bridge checks confirmation; this endpoint additionally checks real daemon state.
	guard := func() error {
		if r.Operation == "restore" {
			if s.Environment.State() != environment.ProcessOfflineState {
				return errors.New("server must be stopped")
			}
			running, err := s.Environment.IsRunning(s.Context())
			if err != nil || running {
				return errors.New("cannot prove server stopped")
			}
			if s.IsInstalling() || s.IsTransferring() {
				return errors.New("server protected")
			}
			s.SetRestoring(true)
		}
		return nil
	}
	j, err := e.Submit(s.Context(), r, guard, func(j engine.Job) {
		if r.Operation == "restore" && j.Status != "unknown" {
			s.SetRestoring(false)
		}
	})
	if err != nil {
		c.JSON(409, gin.H{"error": "operation refused; busy, invalid identity or unresolved prior operation"})
		return
	}
	c.JSON(http.StatusAccepted, j)
}
func export(c *gin.Context) {
	e, r, ok := request(c)
	if !ok {
		return
	}
	ctx, cancel := context.WithTimeout(c.Request.Context(), 30*time.Minute)
	defer cancel()
	// Stage the completed archive before sending any success headers. The engine bounds its size.
	f, err := os.CreateTemp("", "ibyte-export-*.tar")
	if err != nil {
		c.Status(507)
		return
	}
	defer os.Remove(f.Name())
	defer f.Close()
	if err = e.Export(ctx, r, f); err != nil {
		c.JSON(409, gin.H{"error": "export failed or exceeds configured bounds"})
		return
	}
	if err = f.Close(); err != nil {
		c.Status(500)
		return
	}
	c.Header("Cache-Control", "no-store")
	c.FileAttachment(f.Name(), r.Backup+".tar")
}
