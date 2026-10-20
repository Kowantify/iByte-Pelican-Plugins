# Changelog

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
