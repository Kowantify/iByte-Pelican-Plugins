# iByte Restic 2.0.2

Import and install this folder's ZIP with Pelican's Admin Plugins UI. The plugin discovers required Panel service classes and methods at runtime; it does not load an embedded compatibility JSON or require exact source hashes. No Panel core files are modified. Read the source release's README, native restore API limitations, SECURITY and acceptance checklist before enabling a host.

Fresh repositories only. Explicit node links and a matching provisioned node credential profile are mandatory. A durable Panel queue and a shared, lock-capable cache are required. Restart native queue workers through your normal deployment process after plugin code changes. Runtime contract checks do not replace acceptance checks after major Panel API changes.

Uninstall migration refuses to erase operation history or orphan active Restic hosts. Disabling/removing plugin code while Restic records exist makes their schema unavailable; keep the plugin installed until a reviewed retention/decommission plan exists. No repositories are deleted on uninstall.
