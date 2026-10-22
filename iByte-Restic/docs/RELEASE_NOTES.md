# Panel 3.0.2: Pelican rc.2 compatibility retarget

Update only the Panel plugin to 3.0.2 on a Pelican **1.0.0-rc.2** panel; Wings remains preview.2/3.0.0. Import the new ZIP over the installed plugin in Admin > Plugins, choose Install when offered, wait for native installation success, restart the real queue worker (`sudo systemctl restart pelican-queue` or your equivalent) and run `php artisan restic:diagnose`. It must report zero source-file and zero runtime-dependency mismatches: this build re-records the 35 core/vendor file pins and 168 runtime package identities against the operator's actual rc.2 panel, which previously failed the compatibility fence after the panel update.

No plugin behavior changed: no migrations, no new tables, no key, repository or backup operations. Existing Restic repositories, encrypted keys, alert history and migration history are untouched — update over the installed plugin, never uninstall. If the panel still runs the temporary startup guard in `SoftwareVersionService::currentPanelVersion()`, keep it: the early cache-resolution failure is an upstream rc.2 issue (see UPSTREAM report), not something a plugin can or should work around.

The 3.0.1 Application-API deletion flow ships again in this ZIP unchanged (auto final backup + retention + 409-on-failure for API-key server deletions such as Paymenter termination). If your panel had been downgraded to a 3.0.0-preview.5 file set, this update restores it.

---

# Panel preview.5: password-only confirmation and server/repository destruction

Update only the Panel plugin to preview.5; Wings remains preview.2. No new migration, node rebuild or Panel core edit. Wait for native installation success and restart the actual queue worker.

Native admin Delete/Force Delete now asks for the current Panel password and a retention choice, with no typed UUID. Keep a fresh final backup remains the default and requires verified final-backup completion. Force delete server and destroy all Restic repositories skips final-backup and escrow preparation, overrides snapshot locks/stuck metadata for this server, and uses native force deletion. Transfer remains retention-only.

The original captured local repository identities are committed before server deletion. Physical cleanup runs after the server disappears through the authenticated node force-delete endpoint. Offline/busy/ambiguous results remain Destruction pending and retry through the queue/scheduler; they are never reported physically removed until verified. No final snapshot survives verified cleanup. Keys/owner history and original unresolved restore evidence remain.

Combined destruction requires captured local inventory; active transfers, missing locations and unsupported S3 physical deletion are refused before deleting the server. Node path, active-work and restore-journal safeguards remain. If native deletion is interrupted, re-confirm destroy mode to reuse the saved intent. Stop external Restic writers before destruction.

This is an unpublished preview; supported restore API, writer fencing, dependency findings and other production gates remain open. See PROGRESS.md for commands/outcomes. No production access or deployment occurred.

---
# Panel preview.4: failure alerts and escrow diagnostics

Update only the Panel plugin to preview.4; Wings remains preview.2. The native plugin installer applies one additive delivery-receipt column. Wait for successful installation, restart the queue and run restic:diagnose. No uninstall or backup deletion.

Fixed: clearing a notification no longer allows the scheduler to recreate the same delivered alert. Each notification identifies its operation type and ID. Existing failure history and restore protection remain unchanged. Pre-upgrade deleted notifications have no receipt and can appear once during initial catch-up; subsequent dismissal is durable. Old visible notifications gain labels without losing their date/read acknowledgement.

Administrative > Node diagnostics reads addon readiness, the configured profile and optionally a saved operation result. Equivalent CLI: php artisan restic:diagnose-node HOST_ID NODE_ID --operation=OPERATION_UUID. It fetches no keys, submits no work, verifies HTTPS/identities and only displays allowlisted failure details. Omit --operation for readiness alone.

Escrow rejection now names key preparation versus response validation and reports safe connection/HTTP categories without chaining potentially secret response bodies. The specific production escrow/check cause remains unverified until operator diagnostics. This update does not reset keys, initialize over existing data, repair repositories or mark failed checks successful. Production readiness/security/restore gates remain open.

---
# Panel preview.3 compatibility hotfix

Use Panel plugin 3.0.0-preview.3 with unchanged Wings 3.0.0-preview.2. Update the existing plugin in Admin > Plugins; wait for installation, restart pelican-queue and run restic:diagnose. No uninstall, backup deletion, core edits or Wings reinstall.

The old gate rejected any composer.lock byte change. The new gate allows metadata/dev/plugin additions while checking every pinned runtime package against locked and installed versions/references. Other source and binding guards remain. Diagnostics identify actual mismatches without exposing arbitrary source URLs. Native Create displays an actionable notification instead of a generic 500 and dispatches nothing on incompatibility.

486 native Panel checks pass, including 17 new compatibility regressions. The user-provided old hash mismatch alone cannot prove their installed runtime is supported. See PROGRESS.md and TROUBLESHOOTING.md. Production gates in RELEASE_HARDENING.md remain unresolved. Nothing was deployed or published.

---

# 3.0.0-preview.2 — safety and administration update

Staging only. This is not a production approval. See RELEASE_HARDENING.md for exact evidence, compatibility and unresolved gates.

New: fresh locked final backup before native delete/transfer with a second password-confirmed action; retained-repository maintenance and opt-in automatic checks/statistics/pruning; persistent sanitized root failure notifications; password-confirmed retained tar/tar.gz exports; coordinated incremental versions; authenticated empty-node installation health.

One Restic Backups sidebar entry still contains Servers, Stats and Administrative. Keys, exports and destruction remain root-only under Administrative. Native Backup Hosts and customer Backups/Schedules keep their normal roles.

Update both node and Panel to preview.2. Copy the whole Wings Installer folder and run `sudo bash install.sh --allow-preview --node-id NUMBER`; import the matching Panel ZIP, then restart the actual queue worker. No manual hashes, uninstall or repository deletion is needed. Keep restore disabled outside isolated fixtures. A final lifecycle backup needs a free quota slot and never evicts the previous recovery point during preparation.

Demonstrated: actual Linux real-Restic race tests, native SQLite/Livewire, serialized queue and wall-clock native schedule selection, protected upstream router/helper, 16 real HTTPS Panel-to-Wings/Restic checks including timed backup/isolated restore/final deletion/retained export, and real amd64 systemd/Docker install/upgrade/repeat/failed-start rollback/stock rollback with mocked Panel inventory. Two-folder packaging includes precompiled amd64/arm64 binaries; arm64 runtime is unverified.

Still blocked: supported restore API/full writer fence; fresh Symfony/Docker/OpenPGP audit findings; full browser installation/restore and distributed MariaDB/Redis/S3/multinode acceptance. Relocation remains unimplemented; file exports/recovery kits are not automatic relocation. No production deployment or GitHub publication occurred.
# Panel 2.0.0

- Standardized the installable ZIP name to `ibyte-restic-v2.0.0.zip`.
- Replaced exact Panel source and composer lockfile pins with runtime service/method checks.
- Kept the Wings addon at 3.0.0; this Panel-only package does not require a node update.
- Packaging the plugin does not constitute a fresh end-to-end acceptance run.
