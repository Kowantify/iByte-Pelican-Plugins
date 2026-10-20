# Progress — 2026-10-20 (0.1.0-preview.1)

Implemented the first vertical slice of the native plugin and installed it on
the production panel through the native installer.

## What shipped

- Manifest `plugin/plugin.json` — id `ibyte-egg-changer`, panels `admin` +
  `server`, target `^1.0.0-rc.1` (verified against the panel's own caret math
  on the live host: current `1.0.0-rc.1` passes both comparator gates).
- Eligibility engine (`src/Packages/`): pure decision rules in
  `EvaluatorRules` (unit-testable without Eloquent) + `Evaluator` with the
  model/DB queries. Rules verified against the pinned sources: inclusive
  thresholds, package min of 0 = no minimum, server allocation 0 = native
  unlimited (satisfies finite minimums; the panel's own admin UI stores 0 for
  the checked `unlimited_mem/unlimited_disk` toggles, and Wings skips its
  space check at `DiskSpace() <= 0`), invalid/negative allocations are errors
  rather than unlimited, AND within a package, OR across complete packages,
  deduplicated union with deterministic ordering.
- Operation journal (`ibyte_egg_changer_operations`) with
  queued/backup/cleaning/configuring/reinstalling phases, per-server cache-lock
  reservation plus the durable journal gate, idempotency via phase checks at
  job start.
- Queued `SwitchJob` (panel redis queue; `pelican-queue.service` verified
  running): fresh eligibility recheck → suspended/conflict-state gate →
  live-daemon stopped-state proof (state != offline throws) → real native
  recovery backup via `InitiateBackupService` (awaited to `completed_at` +
  `is_successful`; backup uuid recorded; never deleted on failure) → scoped
  root cleanup via `DaemonFileRepository::getDirectory('/')` +
  `deleteFiles` (top-level entries only) → native `EggChangerService` swap →
  best-effort `daemon sync` (failure logged, non-fatal, mirrors the panel's
  own `BuildModificationService`) → native `ReinstallServerService` dispatch
  → completion poll distinguishing completed / failed / needs-reconciliation
  (60-minute window; overdue = needs-reconciliation with backup preserved).
- Admin Filament page (root-admin only): package create/enable/disable/delete
  (mapping-only deletion), hypothetical-allocations eligibility preview,
  operations list with reconcile/cancel actions, diagnostics (schema status +
  native source-contract checks), stuck-reservation release lever.
- Client Filament page (server area, owner-only in this preview): catalog with
  Unlimited display, per-egg qualifying packages, preview with destructive
  scope + variable listing (required/viewable/editable computed per the
  pinned model's accessor), one explicit confirmation, journal-backed
  progress that survives refresh (needs-reconciliation shown distinctly).
- Additive idempotent migration creating the three plugin tables; package
  deletion never touches servers/eggs; uninstall keeps history tables.

## Verification performed

- `php -l` (8.5): all 20 PHP files PASS (both local WSL and on the panel host
  after extraction — "ALL FILES LINT OK").
- `tests/evaluator.php`: 9/9 unit checks PASS (WSL PHP 8.5.11).
- Package: single root folder, forward-slash entry names (21 entries,
  `plugin.json` at root), SHA-256 recorded. Compress-Archive was never used.
- Production install through the native path: folder placed in
  `/var/www/pelican/plugins/`, `php artisan p:plugin:install ibyte-egg-changer`
  → "Plugin installed and enabled"; `p:plugin:list` shows the row
  (enabled, 0.1.0-preview.1, admin,server); classes autoload in tinker; all
  three tables exist per the app's own connections; `/login` 200, `/` 302,
  `/admin/plugins` 302, `/server` 302 (no 500s); zero plugin-related panel
  errors in the log after install.

## Not yet verified (explicit gates)

- No real end-to-end switch on a disposable server yet (the destructive
  lifecycle backup→cleanup→egg-change→reinstall has not been exercised with a
  live node; the journal phases and the completion poll are built but the
  awaited native callback is only source-verified).
- Admin/client UI walkthrough (package creation → visible eligible list →
  preview → confirm) is the operator's manual test.
- Late-callback reconciliation beyond the 60-minute window,
  cooldown/rate limits, delegated subuser policy — documented future work.