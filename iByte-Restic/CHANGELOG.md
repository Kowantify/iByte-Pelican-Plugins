# Changelog

## Panel package 2.0.2 (queue-process binding diagnostics)

- When a required Panel service resolves to the wrong implementation, log the expected and resolved classes, resolved source file, PID and SAPI. The handled exception includes the same process-specific details, allowing queue/web conflicts to be diagnosed against a fresh CLI report.
- No binding policy changed. A mismatch still blocks Restic before node I/O; no new backup is dispatched. No migration, Wings, dependency, key or repository changes.

## Panel package 2.0.1 (compatibility diagnostic fix)

- Check native PHP interfaces with `interface_exists()` as well as classes with `class_exists()`. PHP intentionally returns false from `class_exists()` for an existing interface; this caused the rc.2 Panel diagnostic to falsely report `BackupAdapterSchemaInterface` missing even though its file and declaration are present.
- No schema migration, dependency change, backup/key/repository operation, Wings change or Panel source change. Import this ZIP over the existing plugin ID, then restart the queue and rerun `php artisan restic:diagnose`.

## Panel 3.0.2 / Wings 3.0.0 (Panel-only compatibility release)

- Retarget the Panel plugin from Pelican rc.1 to **1.0.0-rc.2** (commit `f64a167eea349bfbb89cda8732eacf682a82a4b3`). The manifest `panel_version` and the embedded source/dependency contract pins (35 core/vendor files, 168 runtime package identities) were re-recorded against the operator's actual rc.2 panel. All 203 pin checks verify with zero mismatches against that live panel; previously `restic:diagnose` reported the rc.2 `PluginService.php` change and 24 drifted runtime packages (Laravel 13.35→13.38, Filament 5.10.1→5.10.2, guzzle 8.2→8.3, aws-sdk 3.400→3.401.3 and more).
- No plugin source behavior changed in this release beyond the compatibility gate data. The rc.2 startup interruption (rc.2 evaluates plugin manifests from `AppServiceProvider::register()` while Laravel's cache manager is still unresolvable) is an upstream Panel issue; the operator applied a separate temporary Panel-side guard. Plugin code does not touch cache/Redis during registration and needed no workaround.
- Update by importing this ZIP over the installed plugin and reinstalling (same ID: safe, additive). Existing Restic repositories, keys, tables and migration history are untouched. Restart the real Panel queue worker afterwards and run `php artisan restic:diagnose` — it must report zero file and zero dependency mismatches. Wings remains 3.0.0; no node rebuild, no uninstall, no reinstall of nodes, no repository operations.
- The 3.0.1 Application-API deletion flow (`ApplicationApiCall`, `GuardedServerDeletion::autoRetainAndDelete()`, `RetainedDeletion` API scope helpers) ships unchanged in this ZIP, restoring them where a rc.1-era build had been deployed.

## Panel 3.0.1 / Wings 3.0.0 (production release)

- Application API deletions (e.g. Paymenter cancellation) no longer require an interactive password. When a server with Restic backups is deleted through a root-admin Application API key, the plugin automatically creates a fresh locked final backup, verifies it completed successfully (waits up to 30 minutes, aborting deletion on failure), retains the latest snapshot, then allows the native deletion and its subsequent repository cleanup. The web UI delete/transfer/destroy flows keep their existing retention choice and password confirmation unchanged.

## Panel 3.0.0 / Wings 3.0.0 (production release)

- Remove typed UUID confirmation from native admin lifecycle forms; keep current Panel password and bound server authorization.
- Add explicit force-delete-server-and-destroy-repositories mode without a retained final backup, escrow preparation or individual snapshot deletion. Default remains retaining a fresh final backup.
- Persist original local repository identities before native force deletion; queue cleanup after server removal, including retained source nodes. Recover retries/restarts and keep offline/ambiguous deletion pending until verified.
- Preserve keys, audit history and unresolved restore evidence; retain node safe-path, busy and journal protections. Unsupported S3/missing-location cleanup is refused before deleting the server.
- Improve failed snapshot deletion messages; use unchanged Wings preview.2 binaries and retain existing production gates.

## Panel 3.0.0-preview.4 / Wings 3.0.0-preview.2 (unpublished, staging only)

- Fix cleared notifications being recreated on scheduler replay using transactional durable delivery receipts; preserve historical operations, acknowledgement and restore protection.
- Label notifications by operation and ID; display only allowlisted saved failure details.
- Add root-only Node diagnostics and read-only CLI readiness/saved-result checks without fetching secrets or retrying jobs.
- Name the escrow failure stage and safe HTTP/connection category instead of one uninformative error; preserve existing keys.
- Make all native import/reimport fixtures use the current plugin ZIP. Keep unchanged Wings preview.2 binaries and existing production blockers.

## Panel 3.0.0-preview.3 / Wings 3.0.0-preview.2 (unpublished, staging only)

- Replace whole-lockfile rejection with pinned runtime identities in both composer.lock and installed Composer metadata. Plugin additions and lock metadata changes are allowed; changed or missing tested runtime packages fail closed.
- Show safe file/package diagnostics in Administrative and restic:diagnose. Native Create shows an actionable notification without backup or node dispatch when incompatible.
- Panel-only update: unchanged preview.2 node binaries and handshakes. No core edits, migration, backup deletion or node reinstall.
- Add 17 compatibility regressions; 486 native Panel checks pass. Restore/security/acceptance blockers remain.

## 3.0.0-preview.2 (unpublished, staging only)

- Add fresh locked final-backup preparation before native delete/transfer, verified second confirmation, safe cancellation and a free-slot requirement preserving existing recovery points.
- Add retained-repository check/stats/prune, opt-in scheduled maintenance, sanitized persistent root failure notifications and a Needs attention view.
- Add password-confirmed single-use retained tar/tar.gz exports with captured-location/generation checks and bounded private staging.
- Add coordinated incremental versioning and empty-node authenticated installer health. Preserve self-contained two-folder installation and rollback.
- Run real Linux race, native timed database queue and real Docker/systemd installer upgrade/failure/rollback tests. Keep unsupported restore fencing, unresolved upstream dependency findings, relocation and distributed acceptance explicitly blocked. See docs/RELEASE_HARDENING.md.

## 3.0.0-preview.1 (unpublished working revision)

- Organize one Restic Backups sidebar entry into native Servers, Stats and Administrative tabs. Add structured server details and saved storage summaries with measurement coverage/dates and clear deduplication/compression definitions. Confine keys/history/export/destruction/settings/maintenance to Administrative. Backup Hosts retains basic configuration and native backups. Preserve root authorization, password gates, immutable host settings and zero node I/O while rendering.

- Automatically recreate verified absent local repositories on the next native backup or schedule using a fresh numbered generation and random key. Preserve all prior passwords and locations in admin key history; keep old jobs/deletes isolated from replacement leaves. Native rollback/retry preserves prepared keys and no new backup host is required. Requires matching Panel and Wings updates.

- Bundle all node files beside the plain install script when no GitHub target is configured. Copy the Wings Installer folder and run install.sh; no --artifacts, separate downloads or manual hashes. Include a standalone Wings ZIP, automatic architecture/integrity checks, and retain small download mode for configured publication. Copied-folder install/repeat/rollback and both packaging modes are covered by local fixtures.

- Fix blank admin controls under Pelican's default icon-button preference; render labelled keys/history, record cleanup and Manage actions.
- Move forced cleanup exclusively to Admin > Restic Backups. Current admin password approves removal of locked/stuck/missing Panel records without node connectivity. Preserve recovery keys/history and unresolved restore protection.
- Add password-only whole-local-repository force deletion, including locked/unrecognized/corrupt snapshots and retained sources after server deletion/transfer. Exact node/profile/UUID/location checks, active-job exclusion, durable retry, secret retention and retired identities remain mandatory. Requires matching updated Wings; physical S3 deletion is unsupported.
- Ordinary node deletion safely reconciles a mapped snapshot proven absent by a successful repository listing. Ambiguous errors never claim physical deletion success.

- Organize Panel source by responsibility; keep serialized Jobs identities stable and isolate native bindings/observers.
- Consolidate admin repositories, keys and stored statistics under Restic Backups with one host action menu.
- Capture encrypted recovery keys before new backups; backfill existing active repositories using the existing scheduler. No manual capture trigger.
- Measure/persist repository statistics after create/prune; show cached admin/customer metrics and per-snapshot compressed additions. Retry missing accounting through native maintenance without faking backup failure.
- Replace embedded binary installer with small readable HTTPS bootstrap. Keep automatic integrity checks, robust preflight, repeat-safe upgrades and rollback. Release has exactly two operator folders; build/audit files remain internal.
- Preserve native count quota replacement, calendar retention, delayed pruning, signed downloads, retained owner/key inventory and additive missing-table repair. Update over the installed ZIP rather than uninstalling.
- Consolidate current docs and archive superseded chronological reports. Publish candid legacy gaps and production gates.

This candidate is staging only. Public restore API/write-fence, security findings and real Linux/browser/systemd/distributed/remote acceptance remain blocked. No production access/deployment or GitHub publication. Previous unpublished digests are superseded; future published tags/assets must be immutable.

Unpublished preview update: independent random per-server repository passwords, resumable shared-profile key upgrade with committed encrypted escrow acknowledgement, password-protected persistent key history, and native Delete/Transfer latest-only source retention with warnings, older-lock refusal and durable transition reservation. No production-readiness claim.

Unpublished repository-controls update: compact admin inventory with leading keys/history/Manage, native root management and stale-cleanup header actions, explicit root-confirmed live local repository deletion, durable retry/retirement and retained encrypted history. Old queued creates upgrade original-profile escrow before activation. Archived/S3 cleanup remains unsupported.
