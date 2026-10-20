# Backup retention and repository pruning

Installation is documented only in INSTALL_PANEL.md and INSTALL_WINGS.md. Public release contains Panel Plugin and Wings Installer; no uninstall is needed to update.

This revised unpublished preview requires the matching Panel ZIP **and** Wings addon build. Existing restore, Linux/systemd/browser and release security gates remain blocked. No production deployment was performed.

## Configure and use

1. Update the disposable Wings node using the small install.sh as described in INSTALL_WINGS.md. Use its actual node ID; verification is automatic. Update one node at a time.
2. Import/install the matching `ibyte-restic-pelican-plugin-v3.0.0-preview.5.zip` through Admin > Plugins. Plugin installation applies the new retention migration. Restart the actual Panel queue service after updating plugin classes, for example `sudo systemctl restart pelican-queue` where that is the unit name.
3. On the server's existing **Backups** page, choose **Backup retention**. Owner/admin or a subuser with **both backup.create and backup.delete** can edit it. Enable calendar rollup, set **daily 1**, **weekly 1**, and choose a timezone, for example `America/Chicago`.
4. Use the existing **Schedules** interface to create an hourly schedule (minute `0`, hour/day/month/week `*`) and add its native **Create backup** task. Existing schedules need no replacement task. Use native command/stop tasks when the application needs a quiescent backup. Retention runs after verified successful manual/scheduled backups; it has no independent cron or separate customer dashboard.
5. With backup limit 20 and no locks, the policy retains up to one completed weekly representative, one separate completed daily representative and 18 hourly representatives. Missing history makes unused reservations available to hourly copies. Settings apply after the next successful backup; saving alone does not delete anything. Revisit the modal for cached last-run/error information.

## Exact policy

Default: calendar rollup **off**. At full quota, a new manual or native scheduled Restic backup replaces the **oldest completed unlocked successful Restic backup**. Manual replacement requires native delete permission as well as the caller's native create permission. Count includes successful and in-progress backups, matching native `nonFailed()`. Failed backups do not consume those slots. Pending backups are never automatic candidates. All locked/pending or zero quota means a handled refusal. When an admin lowers quota below current usage, creation refuses instead of bulk-forgetting snapshots.

When rollup is enabled, choose representatives in this order:

1. Keep **all locked and pending** snapshots. They consume the server's limit. Never unlock them automatically.
2. Preserve the **newest successful recovery point** before allocating older tiers. When making room before creation, the prospective incoming backup occupies this slot instead.
3. Reserve up to the selected weekly count: newest available snapshot in each distinct **completed ISO week**, Monday start, newest weeks first.
4. Reserve up to the daily count: newest remaining snapshot in each distinct **completed calendar day**, newest days first. A snapshot already chosen for a week is not selected again.
5. Fill remaining slots with newest representatives of distinct hours. Repeated DST hours use their UTC offsets to remain distinct. Selected daily/weekly/hourly points are different snapshots. Current day/week are served by the hourly tier.

If locks/pending work leave too few slots, the newest point takes priority, then weeks, then days, then remaining hours. Daily/weekly counts are best-effort maxima, not additional quota or guaranteed archives. To guarantee a recovery point indefinitely, lock it. Calendar tiers cannot create missing historical snapshots. The policy does not mean “wait until a snapshot reaches this age before allowing deletion.” Do not enable it if you want to retain multiple independent manual copies within the same hour; lock those copies or leave rollup off.

Automatic deletion uses the native delete service and rechecks the actual backup row lock before contacting Wings. Rollup recomputes from current policy/quota/locks for **one forget per queue turn**, retries failures, skips cleanup while a newer backup is pending, and audits each removed UUID. Failed backup completion does not dispatch rollup. A full quota necessarily requires forgetting one eligible point **before** the new backup can start; if creation later fails, the forgotten point cannot be recovered. Readiness, durable queue, throttle and encrypted recovery escrow are checked first, but cannot guarantee later success. Lock anything that must survive a failed replacement.

## Forget versus prune

`restic forget SNAPSHOT` removes exactly the mapped snapshot after native authorization and lock checks. Panel metadata is removed only after the node confirms successful deletion **and all response identity fields match**. It does not reclaim all newly unreferenced data immediately.

A separate durable `prune` job is queued **ten minutes after confirmed successful snapshot deletion**, including deletion of the final native backup. Queued/running/unknown prune work is coalesced per repository. Its immutable host/node/server/profile context survives removal of its backup anchor row. The normal daemon-authenticated server route executes only fixed argv `prune --max-repack-size 1G`: no `forget`, `keep-*`, arbitrary flags, repo deletion or unlock. It cannot select/delete a referenced snapshot, including locked copies or snapshots retained outside the native list. The one-GiB option bounds repacking per run, not total I/O or duration; prune still needs spare disk space and may leave additional repacking for a later run.

This automatic operation is trusted housekeeping after authorized forgetting. Users have no direct repository maintenance endpoint. Root admins can explicitly queue **Restic Backups > Reclaim unused storage** from the native backup host. View **Restic Backups > Operation history** for cached job state. Statistics are captured automatically after maintenance; per-backup added-byte values remain historical creation counters, not reassigned chunk ownership.

Node jobs serialize against backup/restore/export/check/stats for the server, have a two-hour timeout and durable state. Busy nodes retry through the Panel queue. An interrupted prune or ambiguous terminal persistence is **unknown**, never success, and quarantines further node work until operator reconciliation. No automatic `restic unlock`. Node/prune failure leaves already-forgotten snapshots forgotten and remaining referenced snapshots in the repository; it does not justify clearing locks or deleting more records.

## Failure/reconciliation limits

Panel SQL and a remote Restic `forget` cannot share one atomic transaction. A failure after forgetting but before native SQL commits can leave a native record whose snapshot was already removed. Retry the **identified same delete/creation request** after inspecting protected node state: the node's durable delete ID returns its original success, permitting Panel to reconcile. Do not mark backups successful, restore DB rows as if data returned, retry all failed jobs, or clear repository locks blindly. Keep the complete repository plus encrypted recovery kit; a kit cannot restore a forgotten/pruned snapshot.

Native schedule task completion still means creation was dispatched, as upstream implements it; backup success is reported later by the durable polling worker. Schedule continuation is not a backup-completion barrier. Every automatic path uses the same server quota and backup lock authority. Wings/S3 retain native quota behavior and receive no retention action or prune dispatch.

## Local verification and staging gate

`php scripts/package-panel-plugin.php`, `php scripts/test-panel.php`, `php scripts/test-panel-queue.php`, and `RESTIC_TEST_BINARY=/path/to/test/restic go test -count=1 -v ./ibyte/restic/engine` (from wings-addon). CI runs these against pinned sources; actual router middleware/race tests require Linux. The local suite executes native Livewire retention/create actions, actual native RunTaskJob/task registry, real SQLite serialized polling/retention/prune jobs, and real disposable Restic forget/prune/check/export. Node HTTP responses are mocked in Panel tests.

Before production: exercise browser import/modal/permissions, timed native schedules and queue restart on a disposable Linux node; create 20 copies then the 21st, lock all 20 and confirm refusal, test tier transitions across days/weeks, node disconnection, delete/retry/prune failure, full disk and backup-vs-prune contention under the intended MariaDB/Redis deployment. Verify retained files after prune and cached error visibility. Distributed database races and Linux/systemd/browser execution are **not verified here**. Existing restore API/write-fence/security publication blockers still apply.
