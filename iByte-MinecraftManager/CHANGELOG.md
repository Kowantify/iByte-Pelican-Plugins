# Changelog

## 0.1.0-preview.27 - 2026-10-18

- Include software name and Minecraft version alongside the build in installation progress/history.
- Verify three focused label cases and native import; installation behavior unchanged.

## 0.1.0-preview.26 - 2026-10-18

- Bound native release-option labels and summarize large compatibility lists.
- Wrap complete release/support details beneath the selection within the modal.
- Verify native import and two focused rendered Livewire checks; installation behavior unchanged.

## 0.1.0-preview.25 - 2026-10-18

- Read independent directory listings and create sibling directories with bounded concurrency four, validating parents first.
- Resolve CurseForge pack metadata in batches of up to 50 exact projects/files and batch optional Modrinth environment lookup.
- Separate archive staging, decompression, directory scans/creation, file moves and startup timing; show size-check completion progress.
- Show project names alongside release titles in new installation status and history for both providers.
- Preserve direct Wings downloads, metadata/size checks, config/world handling and cancellation semantics.
- Verify native import, eleven install/UI smoke checks, ten small real-HTTP metadata checks and eleven provider contract checks. Broader acceptance remains deferred.

## 0.1.0-preview.24 - 2026-10-18

- Remove eight unused classes and obsolete local download, archive inspection, checksum and polling helpers.
- Require the direct Wings preparation path and read bounded manifest JSON through one helper.
- Share atomic failure cleanup across installer and worker callbacks, preserving unrelated inventory and ignoring late callbacks.
- Retain current features and historical job/schema/dismissal compatibility.
- Verify native import and nine focused smoke checks; broader acceptance remains deferred.

## 0.1.0-preview.23 - 2026-10-18

- Use completion-aware direct Wings pulls with a moving three-request window and no fixed polling sleep.
- Extract on Wings; read only small manifest/index JSON and metadata on Panel.
- Replace payload checksum passes with completion/size checks and distinguish expected hashes in inventory.
- Remove automatic rollback/recovery; keep partial files and release slots on failure/cancellation.
- Recommend a backup in a native Panel dialog before installation.
- Limit validation to native import and seven smoke checks at the user's request.

## 0.1.0-preview.22 - 2026-10-18

- Verify initial downloaded artifacts with four bounded concurrent native reads, retaining publisher hashes, size checks and JAR inspection.
- Share fresh completion listings per folder per poll.
- Show distinct processing phases, phase-local counts, elapsed time and accumulated phase timings.
- Settle current verification requests before cancellation, failure cleanup or recovery.

## 0.1.0-preview.21 - 2026-10-18

- Show full provider-supported Minecraft versions and loaders in release options and selected-release details.
- Keep compatibility labels independent of search filters; show missing metadata explicitly.

## 0.1.0-preview.20 - 2026-10-18

- Add stopped-server Keep files and unlock recovery with worker exclusion and retained backups.
- Cancel active installations cooperatively and retain reservations until recovery finishes.
- Keep Recent installations open during polling; stop polling inactive/recovery-required tasks.
- Submit visible release filters directly, rebuild release options and show Minecraft/platform/channel labels.
- Prefer Release, Beta, Alpha within available choices and defensively filter CurseForge version/platform rows.


## 0.1.0-preview.19 - 2026-10-17

- Verify final files with four concurrent native reads, preserving all SHA-256 and size checks.
- Update progress as files complete and settle pending reads before recovery/cleanup.
- Add real delayed-HTTP tests for speed, bounded concurrency and failure handling.


## 0.1.0-preview.18 - 2026-10-17

- Extract runtime files directly to final paths; eliminate library staging renames and redundant directory creation.
- Batch native rename/delete operations for mods, plugins and routed pack files.
- Share directory metadata, skip absent subtrees and remove redundant per-file byte/state checks; retain final checksums and recovery.
- Test the latest NeoForge 1.21.1 build 21.1.262: 103 to 11 seconds with added request latency; five seconds without added latency.
- Verify native UI installation, browser interactions, game startup, package batches, a real Modrinth pack and native bulk-rename failure recovery.


## 0.1.0-preview.17 — 2026-10-17

- Fix literal Blade/translation leakage in client-only cards; cover available, empty, client-only and failed lookup branches.
- Add View to every catalog card, an explicit release Search form and loader dropdown, and Load more releases in place of release pages.
- Prevent unsearched draft filters from installing or queuing a stale release; retain client-only browsing with disabled install actions.
- Add numbered catalog navigation that scrolls to the top only after successful page changes; verify desktop/mobile and focused buttons.
- Verify 33 live provider/project cases, 99 version/loader contexts, 33 captured-data dialogs and six publisher-verified JARs.
- Verify fresh Wings runtime/mod installs and game startup on NeoForge 26.3, Fabric 1.21.1 and Forge 1.20.1, actual UI queue delivery and failure recovery.
## 0.1.0-preview.16 — 2026-10-17

- Show the latest server-compatible release, Install and Add to queue on each main catalog row.
- Open release history from the project name; live Minecraft version/loader filters reload releases and clear stale selections.
- Keep release browsing separate from the installed runtime; main actions re-resolve compatible releases and validate project IDs.
- Load row metadata lazily with native disabled controls while loading, without delaying catalog search for every project.
- Batch parent-directory listings, avoid duplicate parent preparation and skip cleanup probes for retained output directories.
- Show a finishing phase after verification; preserve checksum checks, collisions and rollback coverage.

## 0.1.0-preview.15 — 2026-10-17

- Replace the visible review/confirmation flow with one Install action for software, releases and modpacks.
- Add a session-backed mod/plugin queue with Add to queue, individual removal, Clear and one batch Install action.
- Resolve queued selections afresh on Install, retain them after failed dispatch and isolate them by actor/server.
- Use native Filament controls and sections, collapse optional target fields, and keep live installation progress on the current page.
- Preserve the existing installer and its automatic authorization, compatibility, stopped-state, integrity and recovery checks.

## 0.1.0-preview.14 — 2026-10-17

- Automatically apply a modpack's exact Minecraft/loader runtime to existing server-local type, version, build and JAR variables through native startup validation.
- Preserve compatible Java startup commands and unrelated JVM/memory settings; include managed variables in stale-review checks, rollback and reconciliation.
- Default mod/plugin catalogs to the installed or explicitly configured runtime, with removable filters retained across providers, sorting and navigation.
- Apply directional Paper/Spigot/Bukkit compatibility consistently; keep Forge/NeoForge, Folia and proxies distinct.
- Reject known plugin entry-point Java mismatches before replacing files, without executing plugins on the Panel or silently changing releases/images.
- Verify real runtime switches, provider installs and compatible plugin loading. Detailed acceptance state and boundaries are in docs/TESTING.md and docs/PROGRESS.md.

## 0.1.0-preview.1 — 2026-10-16

- Initial independent native Pelican catalog and exact-release review preview.
- One client page and one root-admin page, egg/image profiles, encrypted CurseForge settings, MCJars/Modrinth/CurseForge metadata adapters, durable review/readiness activity and permission revalidation.
- Bounded security/planning utilities and deterministic, native, live-metadata and browser tests.
- Reproducible plugin ZIP and compatibility/evidence records.
- Server installation/modification/recovery execution and unattended updates remain disabled. No production or universal runtime support claim.
