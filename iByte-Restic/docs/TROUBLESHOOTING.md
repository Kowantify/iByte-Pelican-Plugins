# Troubleshooting

## Deletion safety binding unavailable / another plugin replaced it

The expected guarded ServerDeletionService did not resolve in the failing process. Older wording blamed another plugin without identifying one. Stale workers, incomplete plugin boot and competing bindings require runtime evidence to distinguish.

A trace naming flat src/Compatibility.php, src/Transport.php and src/RecoveryVault.php predates the current grouped layout: Support/Compatibility.php, Nodes/Transport.php and Recovery/RecoveryVault.php. It describes the process at that timestamp, not necessarily current disk files.

Import the current ZIP over the existing plugin and wait for successful native installation. Do not uninstall or remove backups. Restart the actual queue service afterward; for the unit previously shown by the operator:

```bash
cd /var/www/pelican
sudo systemctl restart pelican-queue
sudo -u www-data php artisan restic:diagnose
```

A root admin can also open Restic Backups > Administrative > Panel diagnostics to inspect the web process. Diagnostics makes no Wings request or binding replacement. `ready` means local bindings and pinned source match; it does not mean node health or production readiness.

Output identifies actual/expected classes, loaded providers, code paths, PID and plugin status. A fresh CLI cannot inspect queue/FPM memory. If the command is missing, that CLI has not loaded this diagnostic update. If it reports mismatch, retain the actual resolution output; do not bypass the safeguard or blindly disable plugins. If it passes while new queue errors still name flat paths, verify the restarted service/process and log timestamp. Web-only stale code may require reloading the actual PHP-FPM service; identify its unit rather than guessing.

| Symptom | Safe next step |
|---|---|
| ZIP uploaded but SQL table missing | Check native install notification/protected logs. Import this revision, use Restic Backups > Finish installation / Repair tables. CLI equivalent: restic:repair-installation. No uninstall or destructive migration. |
| Restic staging-only error | On a disposable test host explicitly enable staging preview. Do not change APP_ENV or bypass the guard. |
| Requires explicit native host-to-node link | Restore the original host's correct node association in native Backup Hosts. Never guess another host/node; operation identity is immutable. |
| Old queue class / backup stuck pending | Restart the actual worker service after import. Inspect the identified failed job and durable operation; retry only its original request ID after confirming state. No blanket retry or fake success. |
| Key not yet in overview | New backup dispatch requires successful escrow. Older active repositories synchronize through the existing Panel scheduler/worker. Inspect those services and protected job errors. A lost escrow table plus offline/deleted node cannot yield historical keys. |
| Statistics pending | Measurements are automatic after new backups/prune; native maintenance queues old/missing measurements. Inspect the recorded maintenance warning and native worker/scheduler. Remote physical billing size is unavailable, not zero. |
| Externally deleted / unrecognized backup still listed | Use the root-only Remove stale Panel record action described below. It removes metadata without node access, preserves escrow/history and never prunes. |
| Server deletion safety binding unavailable | Normal node operations remain blocked. Restart the actual worker after updating and check conflicting plugins. Metadata-only cleanup and additive table repair remain available; neither repairs that binding. |
| Download/delete fails | Check actual protected exception and mapping/readiness. Missing tables and missing node links are distinct. Native delete retains uncertain data instead of removing records blindly. |
| Installer says not published / URL missing | Use the complete new Wings Installer folder or standalone Wings ZIP. Keep payload next to install.sh and run without --repository/--artifacts. Published download mode requires an actual release. |
| Recovery escrow unavailable before backup creation | Install the matching bundled Wings update first. Preserve existing private state/profile files and verify the linked node/profile. This message alone cannot distinguish route/version, TLS, profile/key or recovery validation failures. Inspect protected node logs if it persists; do not bypass escrow or regenerate/wipe keys. |
| Explicitly deleted and retired / asks for new backup host | Update the original Wings node and this Panel ZIP, then restart the actual queue worker. Native create now proves local absence and automatically prepares the next generation and key on the same host. Old keys and locations remain in Key history; no manual host/key reset. An old node receives an update instruction instead. |
| Installer rejects latest/custom Wings | Preserve the node; use only the tested official pair. A new upstream version needs source/compatibility review. |
| Restore refuses | Default safety gate. Only isolated disposable fixtures may enable it. Production write fencing/public dispatch are unresolved. |
| Unknown interrupted node operation | Quarantine is intentional. Preserve state/repository and reconcile the exact job. Do not remove provisioning markers, unlock blindly or reset journals. |

CSP report-only and blocked Cloudflare analytics messages do not explain an application HTTP500. Use the actual Panel exception. Share redacted errors only; logs, recovery kits, APP_KEY and profile passwords are sensitive. No production endpoint access is required for local investigation.

## Remove a backup record after deleting its files outside Panel

1. Import the current Panel ZIP over the installed plugin, wait for native installation success (use Finish installation / Repair tables if an earlier install left missing tables), and restart the actual long-lived queue service (for the known unit: sudo systemctl restart pelican-queue). Do not uninstall.
2. As a root admin open **Admin > Restic Backups**. Click **Remove Panel records** on the server row to discard all records for that source repository, or use the header **Remove stale Panel record** to select just one backup.
3. Enter your current Panel password and submit. No UUID copying, acknowledgement checkbox or node update is needed.
4. Locked, pending and missing records can be removed. Keys/history remain. No daemon request, snapshot forget, prune, profile edit or repository wipe occurs. This works with missing node links, unavailable nodes or the reported binding conflict. Cleanup is exclusively in the admin area.

The explicit admin force action overrides locks, pending-age limits and lifecycle reservations. Other normal backup deletion/retention still respects locks. Non-restore polling is dismissed so late status replies/failed hooks cannot revive it. Unresolved restore evidence and server protection remain. This is a metadata override, not proof of node deletion; it cannot cancel a running node job, clear daemon quarantine/restore journals or repair conflicting service bindings. Any surviving snapshot becomes unmanaged.

With the matching updated node build, ordinary delete also succeeds when a successful repository listing proves the exact mapped snapshot absent. Missing mappings/repositories, failed listings/passwords and identity/root mismatches are not interpreted as absence. A cached previous failed delete remains failed; use the explicit record action for that externally removed backup.

## Keys or repository controls seem missing

Use Admin > Restic Backups. View keys, Key history and Remove Panel records lead each row; Manage contains labelled details, download and Delete entire repository. The previously empty menu resulted from Pelican globally forcing icon-only views on actions without icons. This build explicitly uses button/grouped views, tested with both button_style settings. Path/profile/history columns are optional. Forced cleanup is absent from native customer Backups, even when a root administrator views that area.

Original profile identifies captured older credentials; they remain viewable. New matching node code and queue/scheduler are required for per-server key upgrade. Older queued creates now capture/upgrade escrow before activation, rather than endlessly finalizing original-profile metadata. Unknown/expired node operations are still protected. A blank/Unknown size is not proof of zero storage.

Physical repository deletion is a separate password-confirmed root action. Update the original node before using Manage > Delete entire repository; it must advertise force_repository_delete=local-v1. It destroys the whole approved local leaf, including locked, unrecognized or damaged snapshots, and supports retained sources after server deletion/transfer. Stop external Restic writers first. Active addon jobs and unsafe paths remain refused. An offline source cannot confirm physical deletion; retry the same action after reconnecting or remove metadata only. S3 physical removal remains unsupported. No failed/ambiguous node result is presented as success.
# Panel source contract rejected after an update

Panel preview.3 uses unchanged Wings preview.2. Upload the preview.3 Panel ZIP over the existing plugin, wait for installation success, restart the actual queue service, then run `sudo -u www-data php artisan restic:diagnose` in the Panel directory. Administrative > Panel diagnostics gives the same read-only report for that web process.

`panel_contract.file_mismatches` identifies changed checked core/vendor files. `dependency_mismatches` identifies changed, missing or inconsistent locked/installed runtime packages by name and safe version fields. A whole composer.lock digest change alone no longer rejects a matching runtime: metadata, dev entries and added plugin packages are allowed. Plugin dependency management can also change runtime dependencies; the new report distinguishes these cases.

Restic detects the Panel service methods it uses at runtime and does not require a generated compatibility JSON file or exact composer.lock pins. If the diagnostic reports a missing contract, update Restic for the changed Panel API and run the release acceptance checks. Existing backups and escrow are preserved. A fresh ready CLI process cannot prove an older PHP-FPM/queue process loaded the same code.

The reported smtp.example.com connection failure is separate: it is a placeholder mail host, not a storage error. Configure actual mail through normal Panel administration if needed. Historical failure notifications can remain after the underlying fault is fixed; they do not prove a new backup was attempted.

## Queue worker reports a Restic service-binding mismatch

A `Restic disabled: another plugin replaced the backup retention compatibility binding` error means this process resolved a different `InitiateBackupService` than Restic's guarded adapter. In a queue job this check runs before the Wings request. The persisted create stays queued and is retried with the same request ID; it is not proof the node is backing up. A successful `restic:diagnose` from a new CLI process only describes that CLI container and does not override the queue's error. Plugin 2.0.2 logs the actual resolved class, source file, PID and SAPI in the protected Panel log. Compare that queue-process record with **Admin > Plugins** and runtime diagnostics before changing provider order or disabling another plugin. Do not delete/recreate the pending backup or bypass the guard; once the binding is resolved, let the same queued operation retry.

## Failed checks, recurring notifications or escrow rejection (preview.4)

Update the existing Panel plugin to preview.4, wait for native migration/install success, and restart the actual queue service. Wings remains preview.2. Use Administrative > Node diagnostics, select the original host and node, and optionally enter an operation ID from Needs attention. The result distinguishes readiness/profile/version problems and reads a saved job result without retrying work or fetching keys. The CLI equivalent is:

```bash
sudo -u www-data php artisan restic:diagnose-node HOST_ID NODE_ID
sudo -u www-data php artisan restic:diagnose-node HOST_ID NODE_ID --operation=OPERATION_UUID
```

Replace the placeholders with actual numeric IDs and the full operation UUID. Diagnostics do not erase failed records, change job status or clear restore protection. A missing/deleted server may prevent source-scoped status inspection; inspect its original protected node state instead. Raw unknown node errors stay redacted. A Restic check exit status alone does not prove repository corruption or identify a bad password.

Escrow rejection now identifies key preparation, recovery response or response validation and a safe HTTP/connection category. HTTP 409 means node identity/key/state refusal, not permission to reset keys or initialize over existing data. HTTP 503 indicates unavailable addon/profile state; 401/403 indicates authentication/access rejection. Verify the approved profile and private node files while preserving the existing repository and keys. Never paste password files, daemon tokens or recovery response bodies into logs/chat.

Clearing a delivered notification now stays cleared because an operation delivery receipt persists independently. Failed operations remain in Needs attention. Notifications from before this update can appear once during initial historical catch-up because old clears have no receipt. Subsequent clears do not recreate them. This fixes alert replay, not the underlying repository failure. No production failure cause has been inferred from screenshots.


## Final backup or deletion cannot be confirmed

Retained server deletion intentionally stops when fresh recovery escrow, final-backup completion or older snapshot deletion cannot be verified. A generic historical error does not establish the underlying node failure. In preview.5, confirmed snapshot-delete failures use allowlisted node details; inspect Administrative > Node diagnostics and protected logs for the actual operation. No repair, key reset or deletion success is inferred from a timeout.

If you explicitly want no retained backup, use the native admin Delete option Force delete server and destroy all Restic repositories and confirm with your current Panel password. This bypasses final-backup/escrow preparation and per-snapshot deletion. It commits cleanup before native force deletion, then retries original-node repository removal in the queue. Destruction pending means physical removal remains unverified; offline nodes cannot be erased remotely. Captured local inventory is required. Busy/unsafe paths and unresolved node restore journals are not bypassed. Do not clear restore protection or edit SQL to make an error disappear.
