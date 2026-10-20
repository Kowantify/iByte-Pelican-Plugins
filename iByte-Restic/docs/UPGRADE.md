# Upgrade

Current Panel preview.5 is a Panel-only compatibility hotfix. Existing Wings preview.2 nodes need no reinstall. Import the preview.5 plugin over the existing ID, wait for installation success, restart the queue and run restic:diagnose. Steps involving node installation below apply to initial setup or older node versions.

1. Confirm exact compatibility in compatibility/versions.json and review RELEASE_CHECKLIST.md. This preview is for disposable staging only.
2. Keep protected Panel database/APP_KEY and node config/password/repository backups. Never uninstall the plugin or delete backups to update.
3. Install the matching Wings build on one node using INSTALL_WINGS.md; wait for its verified readiness. Preserve rollback state. Do not upgrade with active/unknown operations.
4. Import the matching plugin ZIP over the existing ID through Admin > Plugins. Wait for native install success; restart the actual Panel queue service so long-lived workers load the new code. Existing Jobs namespaces are preserved.
5. Open Restic Backups. Existing repositories synchronize automatically through the native scheduler; keys captured earlier remain local. If an earlier failed installation left missing tables, use the additive installation repair, not destructive migration commands.
6. Run the disposable native create/schedule/download/delete/retention tests in TESTING.md. Restore remains fixture-only, disabled by default. Preserve the earlier plugin ZIP/database snapshot until acceptance passes.

Published tags/assets must be immutable. This working preview revision is unpublished; use a new version for subsequent public changes. No automatic upgrade to untested newer Pelican/Restic versions.

Earlier preview.2 changed server-repository credentials. Update Wings first, then Panel. Initial creation/scheduled synchronization automatically upgrades active v3 shared-password repositories in two phases; do not discard node private state or old profile files. Wait for escrow/activation and verify a recovery kit before deliberate deletion/transfer. Key history is additive and uninstall-protected; an already deleted/offline repository remains on its captured password.
