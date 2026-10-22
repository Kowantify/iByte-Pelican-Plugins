# iByte Restic preview

Import and install this folder's ZIP with Pelican's Admin Plugins UI. Target: exact official Panel **v1.0.0-rc.2** source (`f64a167eea349bfbb89cda8732eacf682a82a4b3`; re-pinned and verified against the operator's live rc.2 panel — all 203 source/dependency pin checks pass there). No Panel core files are modified. Read the source release's README, native restore API limitations, SECURITY and acceptance checklist before enabling a host.

Fresh repositories only. Explicit node links and a matching provisioned node credential profile are mandatory. Default remains disabled; this build is for disposable staging. A durable Panel queue and a shared, lock-capable cache are required. Restart native queue workers through your normal deployment process after plugin code changes.

Uninstall migration refuses to erase operation history or orphan active Restic hosts. Disabling/removing plugin code while Restic records exist makes their schema unavailable; keep the plugin installed until a reviewed retention/decommission plan exists. No repositories are deleted on uninstall.
