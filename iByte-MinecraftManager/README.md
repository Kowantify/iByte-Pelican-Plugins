# iByte Minecraft Manager 0.1.0

Install Minecraft server software, mods, plugins and supported modpacks through Pelican Panel and stock Wings.

Import the ZIP through Admin > Plugins and install/update it. Configure enabled providers and egg access in Minecraft Manager settings. Store the CurseForge API key in its private settings field. No Wings changes are required.

Before installation, the native Panel dialog recommends taking a backup. Backups are advisory: the plugin does not automatically create one, require one or restore one. Failed and cancelled installations keep partial files and startup changes, release their installation slot and allow another installation. Restore a native backup yourself when needed. Older blocked operations can be dismissed without restoring their files. Historical recovery copies from previous versions are not automatically deleted.

Downloads go directly from the provider to Wings. Completion-aware foreground pulls run through a moving window of at most three requests, with no fixed polling sleep between completed downloads. Current requests settle before a cancellation/failure stops subsequent work. A current transfer may take time to finish; its timeout is bounded.

Wings extracts archives into temporary operation-owned directories and performs native file moves. The Panel reads only bounded pack manifest/index JSON and filesystem metadata; it does not routinely read JARs, archive bodies, old file copies or final file contents. Temporary extraction directories may be visible while an install runs and are removed afterward. No persistent .minecraft-manager directory is created. Metadata traversal still costs API requests for large archive trees.

Completion and expected sizes are checked. Published provider hashes are stored as expected metadata, not presented as measured file checksums. There is no initial/final full-file checksum pass, no automatic rollback and no downloaded JAR inspection. Client compatibility uses provider/manifest metadata. Native Wings handles archive extraction and its own sandbox/disk checks; the former Panel-side ZIP central-directory inspection is removed.

Existing configuration files and worlds are preserved. Tracked old mod/runtime files may be replaced or retired by inventory ownership; content modifications are no longer detected by hashing. Untracked files are not blanket-deleted. Pack manifests select the exact Minecraft/loader runtime, approved Java image, server.jar launcher and existing egg variables. Permission, source-identity, path, provider URL, size and stopped-server checks remain enabled.

The catalog has Install, Add to queue and View controls. View supports Minecraft/loader filtering and shows release channels and complete supported versions/platforms. The inline install status includes stage counts and elapsed/phase timings. The backup reminder also applies when installing the queue.

Validation covers native ZIP import/install with eleven deterministic native Panel/Livewire/HTTP-contract smoke checks covering a mod, a small Modrinth pack/runtime, manifest-only Panel reads, config/world preservation, failure, cancellation, temporary cleanup, worker-failure inventory cleanup, late callback protection and the backup reminder. Live runtime/browser/provider evidence per release is recorded in the project docs under docs/ and compatibility/versions.json.
Preview.24 removes eight unused classes and obsolete local download, archive inspection and checksum helpers. Installation uses one required Wings path. Shared failure cleanup preserves unrelated inventory and ignores callbacks for settled operations. Historical job names, schema and blocked-operation dismissal remain compatible.

Preview.25 lists independent directories and creates independent sibling directories with at most four concurrent native requests. Ancestors are validated first; listings are fresh per phase, size/path/symlink checks remain, and a failed/cancelled batch settles before later work stops. Downloads retain the stock three-request limit. CurseForge pack manifests resolve exact project/file identities in batches of at most 50; optional Modrinth client-only checks use bulk hashes/projects. Missing or mismatched required files fail the installation. Unavailable optional environment metadata never causes required mods to be omitted.

Progress separates archive preparation, decompression, directory traversal, directory creation, file application and startup synchronization. Final size checking reports completed files during scanning. New Modrinth and CurseForge installs show the project name alongside the release title in progress and history. Existing history without a stored project name retains its original label.

Additional focused checks: ten native concurrent loopback metadata checks and eleven deterministic provider batch checks. A small delayed-HTTP fixture checks bounded concurrency and faster metadata completion; this is not a production pack benchmark or a predicted total install time. No fresh real Wings/provider, browser or game boot is claimed.

Preview.26 keeps native release options compact for large compatibility lists. Complete release titles, Minecraft versions and platforms appear below the selection with wrapping. Two focused native Livewire rendering checks cover a 100-version/five-platform release and changing the selected release. Native ZIP import is checked with optional asset building stubbed; no fresh browser visual or install-engine test is claimed for this UI-only patch.

Preview.27 adds the software name and Minecraft version to MCJars installation labels, alongside the build, using the same progress/history display as mods. Three focused label checks (NeoForge, Paper and Forge) pass without additional provider requests. Native import is checked; no install-engine or browser rerun is claimed.
