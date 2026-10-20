# Narrow upstream restore dispatch proposal (not installed)

The checked Panel does not have `RestoresBackupsInterface` or `RestoreBackupService`. Names in this document/patch are proposed additions, not invented existing hooks. The review-only patch is `compatibility/proposals/panel-v1.0.0-rc.1-restore-api.patch`; it is never shipped inside the plugin ZIP, applied by installer or used to claim current production support.

The patch adds one optional capability interface with `restoreBackup(Backup $backup, bool $truncate): void`, one dispatcher service, and routes the two existing native UI/API daemon calls through that service. Providers without the capability still dispatch the exact old Wings/S3 operation. Existing native permissions, download-link generation, transaction, server protection and audit logging remain at their callers. This avoids changing every backup adapter merely to add an optional capability.

An upstream-supported version would let the Restic schema implement the capability and remove its internal `DaemonBackupRepository` container binding. Persistent plugin queue completion could continue using native events, but upstream should also document completion/restore failure status and cancellation semantics. Formal support must include queue/non-HTTP plugin boot and migration/uninstall behavior, not only a UI hook.

Generation and syntax/applicability check:

```bash
node scripts/create-restore-proposal.mjs
# Generate a git diff from the two .build trees; normalize their prefixes as described in script evidence.
git -C .upstream/panel apply --check ../../compatibility/proposals/panel-v1.0.0-rc.1-restore-api.patch
```

The proposal applies against the exact pinned Panel source and the added PHP files lint. It has **not** been applied to a working Panel, accepted upstream, or runtime-tested as a public contract. It is a concrete narrow upstream contribution candidate. This local preview continues using only its gated internal shim, with the supported plugin-only production gate blocked.

## Separate Wings write-fence contract

The additional source trace in UPSTREAM_MAP proves Panel restore dispatch alone is insufficient. Wings needs a maintenance lease acquired before staging/commit that prevents new file writers and waits for existing writers across SFTP, authenticated HTTP files, outstanding upload JWTs and background pulls. Every write path must honor that lease, and the restore worker must retain it until verified completion or rollback. Cancellation/restart must retain quarantine until journal recovery is resolved.

No such global lease API is claimed to exist on the pinned Wings commit. Designing one upstream is preferable to copying/replacing native file routes in the addon. No unverified fence patch is applied to Wings. Until supported and tested, the plugin blocks native restore unless an administrator explicitly opts an isolated disposable fixture into testing.
