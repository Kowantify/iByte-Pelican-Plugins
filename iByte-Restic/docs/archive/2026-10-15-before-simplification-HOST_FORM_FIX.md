# Native backup-host form correction - 2026-10-15

The reported screenshot was reproduced locally in Edge against the exact pinned native CreateBackupHost page. The native resource dynamically inserts provider fields after schema selection but does not fill their state. Consequently `data.configuration.backend`, `staging_enabled` and `restore_fixture_enabled` were undefined. Livewire refused Alpine entanglement; choosing Local filesystem changed the widget while the actual submitted configuration contained only the text profile. Required validation correctly rejected the absent backend. This was a plugin integration defect, not a Wings/repository/credential failure.

`HostForm::register` uses the native resource's `modifyForm` hook and adds a Restic-only schema-change callback. It initializes the complete configuration in the same response as the dynamic controls. Backend/profile defaults are `local`/`default`; preview and restore fixture remain false. Selecting another provider is untouched; selecting Restic drops unrelated provider keys. Editing an existing Restic host keeps saved backend/profile/toggles. Required/allowed-option/profile validation remains enabled.

## Apply the replacement

1. Obtain the newly rebuilt `release/ibyte-restic-pelican-plugin-v3.0.0-preview.1.zip` and verify its new digest from ARTIFACTS.md. This is an unpublished package revision, not a new daemon version. Old ZIP/source/manifest hashes are superseded.
2. In the disposable Panel, Admin > Plugins > Import the replacement over the installed plugin. The pinned import action preserves metadata and queues reinstall for installed plugins. Wait for completion, recycle the usual queue workers, and reopen Create Backup Host. Do not uninstall or delete existing records.
3. Choose Restic. Local filesystem and profile `default` should be initialized. Link the staging node; explicitly enable preview for a backup test. Leave the restore fixture toggle off except for an isolated fixture. If using the standard local installer, profile `default` is correct; an arbitrary label such as `resticwings` works only if a profile with that name was actually provisioned on the node.
4. Save. The selected backend should validate and all toggle values should persist when the host is reopened.

No Wings, node profile, repository, protocol or installer changes are needed. The existing compatibility and production blockers still apply.

## Evidence and limits

`php scripts/test-panel.php` now exercises the actual native Livewire create/edit pages, dynamic state, both opt-ins, Local/S3 backend selection, profile validation, provider switching, and official ZIP re-import/reinstall with existing history. HTTP node operations and asset builds remain mocked.

Loopback Edge browser evidence includes the original required-field message plus three missing-property console errors, then actual widget selection/toggle changes with no console errors and successful native Create persistence in disposable SQLite. Browser artifacts and the reproduction fixture are referenced in TESTING.md. This proves the host-form path; it does not prove full Linux Panel/Wings/browser installation or backup/restore end-to-end.
