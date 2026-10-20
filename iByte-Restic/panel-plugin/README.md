# iByte Restic preview

Import and install this folder's ZIP with Pelican's Admin Plugins UI. Target: exact official Panel v1.0.0-rc.1 source. No Panel core files are modified. Read the source release's README, native restore API limitations, SECURITY and acceptance checklist before enabling a host.

Fresh repositories only. Explicit node links and a matching provisioned node credential profile are mandatory. Default remains disabled; this build is for disposable staging. A durable Panel queue and a shared, lock-capable cache are required. Restart native queue workers through your normal deployment process after plugin code changes.

Uninstall migration refuses to erase operation history or orphan active Restic hosts. Disabling/removing plugin code while Restic records exist makes their schema unavailable; keep the plugin installed until a reviewed retention/decommission plan exists. No repositories are deleted on uninstall.
