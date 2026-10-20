# Legacy inventory

Pinned legacy commit: `4ce6c042086f8e359ce5910422e02823b500ec12`. All tracked files are inventoried below; duplicate Go implementations have identical content. No automated test files or native Pelican plugin manifest were present.

| File | Lines | SHA256 |
|---|---:|---|
| WINGS_ADDON_DESIGN.md | 116 | fc31416594eb4e53d977e6ed86bd1cbfc76bf9e838d8f2be5193c0415110a078 |
| admin/view.blade.php | 580 | 86f1b299cdd74aa87d941a57e1fcc63f091db39d4a6f71e41b887ddae644a7c2 |
| admin/wrapper.blade.php | 53 | 8782c2760b8fd806fe033d57dcda04eabd2d442da1f3b0f9cc8fca51fd45d3e2 |
| app/Http/Controllers/Admin/Extensions/resticbackups/resticbackupsExtensionController.php | 2455 | dd3a258cba8fe86e6674af1da70276e400e4a18c154259c7f8716a5a1ce1064c |
| clientroutes.php | 4 | c41f7dc9a440e7ae955320498c6d1dad6524f9de71afc58cfb6f1daab4c6d7fc |
| components/Components.yml | 4 | 40285ce0102f8957403e827a364a66661c188bfe7d05252f9cc85463a1262fa3 |
| components/ResticBackupsTab.tsx | 1242 | 8109eb55c078f3a71ccb26309b569c3e2b7929b300e832d5cf617c9045cce6f1 |
| conf.yml | 38 | 583f4ad76e498e5fd2a0210ef7fc0af276e8f2d483b8b2b6d075f8a12cacf2fd |
| console/Console.yml | 2 | 684d157068eed79946603504b95081e81e4e0aa5d052d5574e96752aa5c7ea46 |
| console/restic_schedule_runner.php | 189 | 44b0a661888915e5816add21840fb24a7ed753b97866c0f49ee1278a5fa6f124 |
| database/migrations/2026_01_25_000000_create_server_restic_keys_table.php | 23 | 19219360a09506ea7f6c9822d3318b060c0306c738930d5ccb3fd24157ecf0c6 |
| database/migrations/2026_01_31_000002_create_restic_backup_notes_table.php | 27 | 0910e26cd0f864104a630a808936d310f8c4ff28c78d72a1c320ea80175a73b3 |
| database/migrations/2026_02_01_000001_create_restic_policies_table.php | 37 | eb2d363ac94f61ac1108557d78861843ee62b3fa8dafdf2b23a065285962d2bf |
| database/migrations/2026_02_01_000002_create_restic_key_history_table.php | 25 | 980aec8f89d663efcedacb6659a810b4947cad60f54b6b86dde230f7ee7bac53 |
| database/migrations/2026_02_01_000003_create_restic_job_history_table.php | 29 | 46ea508b308ee57c2f231aed8d74cb433857478922bdba51d527757d518574f1 |
| fs/private/EXTENSION_GUIDE.md | 107 | 9170a9ed41e272d93837b8940c277a4901b34beaf1f7ac6a68ee79e9ee2e11d7 |
| fs/private/Wingsrestic.sh | 53 | 441dc5a04e63f4e083eac4f083bc9353944258d1a7026aa21c5a804e2622733e |
| ibyte/restic/handlers_admin_download.go | 673 | 1dafe4693e78856316da285a9e94745e85175ac423183a9a3c6be16a6e8fc6b1 |
| ibyte/restic/handlers_backups.go | 358 | 43948479e797df3716c9ce198505c26d67358e4a5092b0d38c66823df0fdd3de |
| ibyte/restic/routes.go | 43 | 03a4fd4e3b4d71133b6d6bcb9cd3ea590a26e7c83b13c200af450962114149c8 |
| ibyte/restic/service.go | 688 | b81955af9e76cf341c4efc97915b457d2b83bbdf069943199d45cf992e5dd340 |
| icons/restic.png | 480 | 5a4441d6c3c685e8ee7bb4b9c912405206e7c5bb33b03613df553403e006841c |
| patches/router-v1.13.1.patch | 23 | f54a57cba5ae2fa21a5b533f6e3018db8216def918a17225d78f588f7aa9425e |
| scripts/build-wings-restic.sh | 219 | 5c1519aafb2901b21d68fbd5c91acc1ca3aa7883bb9c3d1085ea580eed9f0f40 |
| web.php | 71 | 708664f20191588139dcb27acacf5cc1e1796c0e5ce8460b789266fcf0914f27 |
| wings-addon/ibyte/restic/handlers_admin_download.go | 673 | 1dafe4693e78856316da285a9e94745e85175ac423183a9a3c6be16a6e8fc6b1 |
| wings-addon/ibyte/restic/handlers_backups.go | 358 | 43948479e797df3716c9ce198505c26d67358e4a5092b0d38c66823df0fdd3de |
| wings-addon/ibyte/restic/routes.go | 43 | 03a4fd4e3b4d71133b6d6bcb9cd3ea590a26e7c83b13c200af450962114149c8 |
| wings-addon/ibyte/restic/service.go | 688 | b81955af9e76cf341c4efc97915b457d2b83bbdf069943199d45cf992e5dd340 |
| wings-addon/patches/router-v1.13.1.patch | 23 | f54a57cba5ae2fa21a5b533f6e3018db8216def918a17225d78f588f7aa9425e |
| wings-addon/scripts/build-wings-restic.sh | 219 | 5c1519aafb2901b21d68fbd5c91acc1ca3aa7883bb9c3d1085ea580eed9f0f40 |

## Findings from implementation review

- `wings-addon/scripts/build-wings-restic.sh` explicitly clones Pterodactyl Wings v1.13.1, installs unverified tools and replaces the running daemon. It is not a Pelican build or safe unattended installer.
- `service.go::repoName/repoPath` uses mutable owner names and fallback repo discovery; lexical `cleanUnderOrEqual` does not resolve symlink components. Jobs live only in a process-global map.
- `handlers_backups.go::createBackup` takes a snapshot before enforcing backup-count/repo-size limits. A locked limit failure can leave an unreported snapshot. Deletion uses `forget --prune` and tag changes rewrite snapshot IDs.
- `handlers_backups.go::restoreBackup` restores into the live volume after path checks, without a staged commit/rollback transaction. The original root-target selection needed replacement.
- `service.go::runResticInDir` uses argv execution (safe idea retained), but inherits the whole daemon environment and passes passwords in environment variables. Output can contain repository paths and is returned to handlers.
- `handlers_admin_download.go::unlockRepo` invokes unlock without proving no worker owns the lock. Repo deletion/archive/inventory endpoints exist beyond the design document route list.
- Controller `generateEncryptionKey` first calls repository deletion, then stores plaintext keys in `restic` and historical tables and exposes an admin key view. This behavior is expressly excluded.
- `console/Console.yml` runs a separate every-minute scheduler. The React component injects a parallel backup/schedule tab; the wrapper injects archive-and-delete UI. Neither is native Pelican integration.
- `clientroutes.php` contains only a removal comment; browser extension routes actually live under authenticated `web.php`. Controller resolves old Pterodactyl table/permission conventions and is not directly portable.
- Notes, dates/cursor filtering, SFTP temporary tar.zst downloads, repo-size multiplier, key history and dormant/archive repository tools are real legacy features, not assumed parity in this preview.

This is a source feature/security review, not proof that every legacy deployment path ever worked.
