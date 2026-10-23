# iByte Restic Backups for Pelican

Native Restic backup-host provider for Pelican. **Panel package 2.0.2 / Wings addon 3.0.0.** See docs/RELEASE_HARDENING.md for the accepted risk register.

Panel 2.0.2 reports the resolved class and source file when a compatibility binding differs in a queue or web process. Panel 2.0.1 fixed interface detection. The Wings addon remains version 3.0.0, so existing nodes need no reinstall. See [troubleshooting](docs/TROUBLESHOOTING.md).

It also keeps cleared failure alerts dismissed, labels checks by operation ID, and adds root-only **Administrative > Node diagnostics** to read readiness and saved failures without retrying work or fetching keys. Escrow errors identify the failed stage and safe HTTP category. Existing failure history and recovery protection remain intact.

Latest changes and exact acceptance results: [validation report](docs/RELEASE_HARDENING.md). Final-backup preparation, retained maintenance/alerts and tar.gz exports are implemented; documented acceptance limits remain in RELEASE_HARDENING.md.

The installation directory has only two folders:

```text
release/
  Panel Plugin/
    ibyte-restic-v2.0.2.zip
    README.md
  Wings Installer/
    install.sh
    README.md
    payload/       (all node binaries and installer; unpublished builds)
```

1. Copy the **entire Wings Installer folder** to a disposable Wings node and run `sudo bash install.sh --allow-preview --node-id NUMBER` inside it. Replace NUMBER with the Panel node ID. The folder is self-contained: no GitHub setup, separate artifact copying or manual checksums. See [Wings installation](docs/INSTALL_WINGS.md).
2. Upload the matching plugin ZIP through **Admin > Plugins > Import from file**, then use Install if offered. Update over the existing ID; do not uninstall or delete backups. Wait for installation success, then restart the actual Panel queue service.
3. Create a native **Restic** backup host, select its nodes explicitly, and use profile `default`. Keep the restore fixture switch off unless following the isolated acceptance procedure.

The script automatically uses verified adjacent payload files when no GitHub target is configured. Published packages can use the smaller HTTPS download mode instead. Verification, preflight, repeat-safe replacement and rollback are automatic; operators enter no hashes. Binaries are ordinary files, with no encoded payload or download-and-execute pipe. A stock Go executable cannot be patched to register new routes: our central build overlays the isolated addon onto pinned official Wings source. Untested newer upstream versions are refused.

Administrators use **Restic Backups** for repository/owner inventory, recovery keys and stored statistics; native backup-host pages have a single Restic Backups action menu. Each server repository has an independent random password. Current keys and version history are encrypted in Panel escrow automatically before new backup dispatch; existing v3 shared-profile repositories upgrade with a committed escrow acknowledgement before the old key wrapper is removed. The existing Panel scheduler backfills older active repositories and refreshes stale measurements. Each new backup/prune captures repository statistics; views read saved results. Key reveal/export still requires root authorization and current-password confirmation. Protect Panel APP_KEY and encrypted database backups.

Customers keep Pelican's native **Backups** and **Schedules**. Size shows compressed data added by that snapshot, with logical restore size separately identified. Repository total, dedup estimate and compression are separate cached statistics. Shared chunks have no exact exclusive per-backup disk size. Count quota replacement, lock protection and optional daily/weekly/hourly retention are integrated into native creation; unreferenced storage pruning follows deletion automatically.

Read only what you need:

After deleting a local repository, create another backup normally on the same host. The matching Panel/Wings update proves the old leaf is absent, prepares a fresh numbered generation and key, and keeps every old key/location pair in admin Key history. Native schedules follow the same path. No new host or manual key reset is needed; existing paths are never wiped to renew. See RECOVERY.md.

Admin navigation has one **Restic Backups** sidebar entry containing **Servers**, **Stats** and **Administrative** tabs. Keys, recovery export, destructive cleanup, settings and maintenance belong to Administrative; native Backup Hosts keeps basic configuration and its existing backup list.

| Task | Document |
|---|---|
| Install/update Panel; three admin tabs; missing tables | [INSTALL_PANEL](docs/INSTALL_PANEL.md) |
| Install/update Wings; rollback | [INSTALL_WINGS](docs/INSTALL_WINGS.md) |
| Admin tabs, keys, storage and server lifecycle | [RECOVERY](docs/RECOVERY.md), [STORAGE_AND_LIFECYCLE](docs/STORAGE_AND_LIFECYCLE.md) |
| Quotas and retention | [RETENTION](docs/RETENTION.md) |
| Code layout and native integration | [ARCHITECTURE](docs/ARCHITECTURE.md), [UPSTREAM_MAP](docs/UPSTREAM_MAP.md) |
| Legacy gaps and production gates | [FEATURE_PARITY](docs/FEATURE_PARITY.md), [RELEASE_CHECKLIST](docs/RELEASE_CHECKLIST.md) |
| Reproduce verification | [TESTING](docs/TESTING.md) |

Upstream source targets and release hashes live in [compatibility/versions.json](compatibility/versions.json) as build metadata. The Panel manifest targets v1.0.0-rc.2; Wings remains v1.0.0-rc.1. The plugin checks runtime service/method availability and does not load those JSON pins. Linux amd64/arm64 artifacts are built, not proven Linux runtime support. Source, SBOMs, manifests and loose binaries live under ignored `.build/release-artifacts`, outside the operator installation folders.

Native admin Delete offers two choices with current Panel password confirmation only: keep a fresh final backup (the default), or force delete the server and queue destruction of all captured local Restic repositories. Destruction skips final-backup preparation and individual snapshot deletion; unavailable nodes stay pending until storage removal is verified. Encrypted key/owner history remains. Transfer keeps the final-backup policy; snapshots do not transfer. See [recovery and lifecycle](docs/RECOVERY.md).

Admin repository rows lead with readable View keys, Key history and Remove Panel records controls. Password-confirmed metadata cleanup works offline for locked/stuck/missing records. Manage > Delete entire repository force deletes all local snapshots, including unknown/corrupt/locked data and retained deleted/transferred sources, after updating the source Wings node. Both require only the current root admin password and preserve keys/history and unresolved restore protection. Cleanup is exclusively in the admin area. Unsafe paths and live addon jobs are refused; physical S3 removal is unsupported. See INSTALL_PANEL.md and RECOVERY.md for exact scope and retry behavior.
