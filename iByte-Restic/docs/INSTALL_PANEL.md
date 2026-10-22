# Install or update the Panel plugin

Use a disposable Panel and verify the runtime API checks before enabling Restic on production. Package 2.0.0 detects required service methods instead of reading embedded source hashes; see RELEASE_CHECKLIST.md for the remaining acceptance gates.

1. For a new node, install Wings preview.2 first (INSTALL_WINGS.md). Keep profiles, keys and rollback state. Existing preview.2 nodes need no reinstall for this Panel-only preview.5 update.
2. In **Admin > Plugins > Import from file**, upload `Panel Plugin/ibyte-restic-v2.0.0.zip`, then use Install if offered. Update over the existing plugin ID; do not uninstall, unlink nodes or delete backups.
3. Wait for native installation success. Upload alone does not prove dependencies, assets or migrations completed; keep the existing queue worker and Panel scheduler running.
4. Restart the actual long-lived Panel worker after installation so it loads the new code. For a service named pelican-queue: `sudo systemctl restart pelican-queue`.

   From the Panel directory, run `sudo -u www-data php artisan restic:diagnose`. It checks source files, locked and installed runtime packages and safety bindings without contacting nodes. If not ready, see TROUBLESHOOTING.md. Existing configured hosts can be kept; steps below cover new setup.

   Preview.4 adds an automatic additive alert-delivery migration. For escrow/check failures, open **Restic Backups > Administrative > Node diagnostics**, select the host and linked node, and optionally copy an operation ID from Needs attention. Run the read-only checks. This fetches no keys and never resubmits a job. Existing Wings preview.2 nodes remain compatible.
5. Create **Admin > Backup Hosts**, choose Schema **Restic**, explicitly link the correct nodes, select local filesystem and profile `default`, then save. That page contains basic storage configuration and the native backup list.
6. Open **Restic Backups > Administrative > Preview settings**, select the new host and enable preview for disposable staging servers. Leave restore fixture testing off unless following the isolated acceptance procedure. Safety settings remain immutable while native backups reference the host.
7. Use the server's native Backups and Schedules. Set native count limits normally; optional calendar retention and cached customer storage remain on that existing interface.

The left sidebar has **one Restic Backups entry**. Its three tabs are inside that page:

| Tab | Purpose |
|---|---|
| Servers | Search server/owner inventory, see source retention and last successful backup, open structured repository details and native server pages. |
| Stats | Saved repository file totals, logical restore data, deduplication and compression, coverage and measurement dates. Missing measurements show unavailable. Definitions explain shared chunks and per-backup added bytes. |
| Administrative | Password-protected keys/history/export and repository deletion; forced Panel record cleanup; preview settings, optional maintenance, diagnostics and additive table repair. |

Keys are captured before dispatch and existing repositories synchronize through the native scheduler. Statistics are saved after backups and pruning. Viewing tabs makes no node requests. No routine key capture or statistics refresh is required. Only root administrators can access this area; key disclosure/export and destructive overrides require their current Panel password.

If a previous incomplete install left missing tables, use **Restic Backups > Administrative > Finish installation / Repair tables**. The equivalent additive repair from the Panel directory is:

```bash
sudo -u www-data php artisan restic:repair-installation
sudo systemctl restart pelican-queue
```

Use your actual service user/unit. Repair preserves existing data and handles tables missing despite recorded migrations. It cannot reconstruct historical keys lost from a deleted database table for an offline/deleted node. Never use migrate:fresh, reset or rollback for repair. Wings/S3 remain independent.

To discard missing or stuck backups, use **Administrative > Remove Panel records** beside the server, or **Remove stale Panel record** in the header for one backup. Approve with your current Panel password. This removes metadata even for locked/pending backups without contacting Wings. Keys/history and unresolved restore protection remain; node storage is not deleted.

To destroy actual storage, use **Administrative > More > Delete entire repository** on the correct row. The warning identifies the local repository. Your current Panel password approves permanent destruction of all its snapshots, including locked/unrecognized/corrupt data. Stop external Restic writers first. An updated reachable source node, safe path and no active addon work are required. Panel records are removed only after verified node success. Retained sources after server deletion/transfer are supported; physical S3 deletion is not.

After confirmed local deletion, create normally on the same host. The matching node implementation proves absence, prepares the next generation and a fresh key, and keeps old key/location pairs in **Administrative > Key history**. Native schedules use the same path. No new host or manual capture is required. See RECOVERY.md for retry and recovery limits.

## Delete a server without keeping backups

Open the native admin server Delete dialog. Keep a fresh final backup is the default. To discard everything, choose Force delete server and destroy all Restic repositories, then enter your current Panel password. No server UUID is requested. The server is force deleted and captured local repository cleanup runs in the existing queue, without creating a final backup. Offline nodes stay pending until deletion is verified; encrypted keys/history remain. S3 and uncaptured repositories are unsupported for this combined action. See RECOVERY.md. This Panel-only update does not require reinstalling a compatible Wings preview.2 node.
