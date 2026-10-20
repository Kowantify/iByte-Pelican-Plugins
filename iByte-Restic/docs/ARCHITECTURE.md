# Architecture and code map

Pelican retains native Backups, Schedules, permissions, quotas and host/node associations. The plugin registers a third schema, never a replacement backup page. Queue class names remain stable across the source reorganization.

```text
panel-plugin/src/
  ResticPlugin.php, ResticSchema.php    plugin/adapter entry points
  Providers/                          dependency registration only
  Admin/                              native forms, three admin tabs and scoped actions
  Backups/                            operations, retention, download
  Integration/                        pinned native bindings and model guards
  Jobs/                               durable, stable serialized job classes
  Nodes/                              authenticated TLS-verified transport
  Recovery/                           encrypted escrow, owner history, reveal/export
  Storage/                            persisted metrics and native display
  Support/                            compatibility, additive installation, maintenance
  Console/                            exceptional installation repair
wings-addon/
  ibyte/restic/                        protected routes and isolated execution engine
  cmd/node-tool/                      node preflight/readiness helper
  patches/                            tiny router registration; separate dependency lock
  scripts/                            central reproducible build
scripts/                              packaging, tiny bootstrap, robust inner installer, tests
compatibility/                        exact source/contract pins; unapplied API proposal
docs/                                 current operator/maintainer guides
  archive/                            historical decisions/results; not install instructions
  evidence/                           raw test/audit evidence
release/                              two generated operator folders only
.build/release-artifacts/              generated binaries/manifests/source/distribution/SBOMs
```

Creation flows through native initiation/quota/RBAC, mandatory recovery escrow, a persisted immutable operation, after-commit native queue, authenticated addon job, verified snapshot mapping and stored metrics, then the native completion event. The addon measures repository storage after each successful backup/prune. A measurement failure leaves snapshot success intact but records a maintenance warning; the existing scheduler retries stale/missing measurements. Admin/customer rendering reads persisted results without node I/O.

The existing Panel scheduler runs a bounded repository-synchronization dispatch every five minutes. Hour-old/missing escrow or measurements queue a unique CaptureRecovery job. Capture precedes new creation; retained/deleted repositories remain readable from local encrypted escrow without a live server. No separate backup scheduler exists. Pending/unknown node operations remain protected, never blindly retried as new requests.

Identity is profile/host ID/node ID/server UUID, with full immutable snapshot IDs tied to Panel backup UUIDs. Owner names are searchable metadata, never storage paths. Jobs/mappings are mode0600 atomic durable files; interrupted mutations become unknown. Execution uses fixed argv, approved profiles, bounded concurrency/output/deadlines and the actual daemon server root. No arbitrary repository/root/CLI flags arrive from customers.

Only router/router.go changes upstream Wings source: one import and one protected-group registration call. A separate dependency lock patch is explicitly audited. All execution/helpers live under ibyte/. Normal Wings/S3 calls delegate unchanged. No Panel core patch is installed.

Restore uses an isolated source-hash-gated internal DaemonBackupRepository binding because the public adapter has no restore callback. It requires truncate confirmation, stopped-server proof and an explicit fixture-only opt-in. Staged/verified data, journaled commit, rollback and uncertainty quarantine are implemented, but a supported public restore API and full Wings in-flight write fence are missing. See UPSTREAM_MAP.md and UPSTREAM_API_PROPOSAL.md; production remains blocked.

Downloads use short-lived user-bound one-use signed Panel routes and bounded node tar staging. Keys require root/current-password/one-use reveal or export, are encrypted at rest, and never appear in normal Livewire payloads. Native admin Delete/Transfer confirmations apply latest-only source retention with password/UUID confirmation, durable reservation and prior committed owner/key/snapshot escrow. Unconfirmed service/API calls stay guarded. Automatic relocation and final snapshot-on-delete are absent. See RECOVERY.md, RETENTION.md and STORAGE_AND_LIFECYCLE.md for lifecycle limits.

Per-repository random passwords live in private node state. Existing v3 shared-key upgrades prepare/add/verify a unique wrapper, commit encrypted Panel key versions, then finalize only on a matching committed escrow receipt. Creation refuses an unprepared/unfinalized key. Offline/deleted-server key history reads escrow without a node. Native Transfer creation detaches its retained source backup in its own transaction before daemon notification; destination backups get a separate immutable node identity.

ForcedRepositoryRemoval coordinates explicit root/password-only whole-local-repository deletion: saved source identity/location, matching node capability, committed force intent, fixed authenticated node operation and verified response before metadata removal. It deliberately bypasses snapshot recognition/locks, damaged Restic state and live server lookup. The isolated maintenance route derives its leaf from node config and exact identity; it accepts no path. Active addon work and unsafe paths remain refused. RecordRemoval separately supports admin metadata-only overrides without node I/O. Keys/owners and restore protection remain; the next native create may prepare a fresh numbered generation after verified local absence. No extra scheduler is introduced. The older conservative RepositoryRemoval remains an internal tested path, not the current admin force action.

RepositoryGenerations preserves generation-zero paths and state hashes. Later generations use sibling leaves and distinct key/marker/mapping/job identities. Generation is persisted in escrow metadata and immutable operation payloads; old backup UUIDs keep their original generation. Prior encrypted key fingerprints map to prior repository locations/owners. Native creation/escrow roll back together; preparation is retry-safe and never wipes existing paths. Existing backup and scheduling APIs remain native; repository-state inherits daemon/server authentication. No migration or upstream router patch expansion is needed.

Admin navigation uses one RecoveryResource and three ResticPage subclasses: ListRecoveryRepositories (Servers), RepositoryStatistics (Stats) and RepositoryAdministration (Administrative). The base page supplies native top subnavigation and common missing-install messaging. Resource authorization applies at mount and hydration; each page registers only its own table/actions. RepositoryColumns/RepositoryDetails provide nonsecret inventory; CredentialActions/RepositoryActions own password-protected controls; HostActions owns preview and optional background maintenance. Basic host forms preserve safety flags from the server-side model rather than accepting hidden-field edits. Stats caches validated current-generation measurements within each request and never contacts nodes. No frontend framework, Panel core modification or extra sidebar resource is introduced.