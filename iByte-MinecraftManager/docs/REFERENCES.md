# Source and contract ledger

## Runtime requirements and default filters (2026-10-17)

- [Modrinth modpack format](https://support.modrinth.com/en/articles/8802351-modrinth-modpack-format-mrpack): exact Minecraft and loader dependency versions determine the pack runtime.
- [Modrinth project search](https://docs.modrinth.com/api/operations/searchprojects/): AND facet groups and OR entries allow a Paper target to include publisher-tagged Paper, Spigot and Bukkit releases. Exact version/platform filtering also applies to release lists and installation reviews.
- [CurseForge API](https://docs.curseforge.com/rest-api/): Minecraft Bukkit class and mod loader enums have different meanings; plugin queries must not send a Forge enum. Unsupported Folia/proxy platform filtering is visible rather than silently broadened.
- [Paper plugin formats](https://docs.papermc.io/paper/reference/paper-plugins/), [plugin.yml](https://docs.papermc.io/paper/dev/plugin-yml/) and [Folia support](https://docs.papermc.io/paper/dev/folia-support/): compatibility is directional and Folia requires explicit support. Existing worlds are preserved, not automatically converted between software or versions.
- [Java class-file versions](https://docs.oracle.com/javase/specs/jvms/se25/html/jvms-1.html) and [multi-release JAR behavior](https://docs.oracle.com/en/java/javase/25/docs/specs/jar/jar.html): bounded entry-point inspection rejects a known Java mismatch before promotion without executing provider code on the Panel. Real WorldEdit 7.4.6/Java 21 failure and explicit 7.3.10 success are retained separately.

Native RC.1 StartupModificationService, VariableValidatorService and server-variable relationship code were inspected. Native startup modification writes all defined variables, so runtime updates merge fresh unrelated values instead of resetting them to egg defaults. Managed variable snapshots, stale reviews and rollback remain server-scoped. D026 records implementation decisions and testing boundaries.

## Reported-pack investigation (2026-10-17)

- [cosmii02 Pelican Modpack Manager CurseForgeService](https://github.com/cosmii02/pelican-modpackmanager/blob/main/src/Services/CurseForgeService.php), MIT: inspected getDownloadUrl and exact file-ID/filename public ForgeCDN construction. Independently implemented identity/hash/size validation; no third-party code copied.
- [Official Pelican Modrinth plugin](https://github.com/pelican/plugins/tree/main/minecraft-modrinth): native Panel/Wings file integration remains the transport basis.
- [itzg auto-CurseForge documentation](https://github.com/itzg/docker-minecraft-server/blob/master/docs/types-and-platforms/mod-platforms/auto-curseforge.md): server/client filtering and unavailable files are explicit installer concerns; a client manifest alone does not establish dedicated-server compatibility.
- [Official Wings RC.1](https://github.com/pelican/wings/releases/tag/v1.0.0-rc.1): unmodified Linux AMD64 release, verified GitHub asset SHA-256 19dda386e02041da1f1fbcc73f34e4bb01c1fbc2d499aac79a6be3a51ba7f0e8.
- [Debian official rootfs artifacts](https://github.com/debuerreotype/docker-debian-artifacts): Debian 13 trixie revision a3751996111ce2c1c904050c84440184535e23b1, rootfs layer SHA-256 4846f29007305297af653c57bf9b60ba8f676a38695c68d98460063442581671, checked against OCI manifest before import. Acceptance uses a new distribution and private Docker socket; no existing distro reused.

Inspected 2026-10-16 and updated 2026-10-17. Source observation is distinct from runtime testing. Exact pins and test versions are in [versions.json](../compatibility/versions.json). The user explicitly superseded the original AGENTS.md installation restriction; downloaded references do not change that authorization or authorize unrelated actions.

## Native integration

- [Official plugin documentation](https://pelican.dev/docs/panel/advanced/plugins/): manifest/root identity; auto-discovered service providers, commands, views, translations and migrations; settings interface and limited stable surface.
- [Panel a3b415f](https://github.com/pelican/panel/tree/a3b415fe4cdb8670ecad782f1d54020f166ab725): `app/Services/Helpers/PluginService.php`, `app/Jobs/Plugin/{InstallPlugin,UninstallPlugin}.php`, `app/Models/{Plugin,User,Subuser,Egg,Server}.php`, `app/Providers/Filament/{ServerPanelProvider,AdminPanelProvider}.php`, `app/Enums/SubuserPermission.php`, `app/Contracts/Plugins/HasPluginSettings.php`, `app/Repositories/Daemon/DaemonFileRepository.php`. AGPL-3.0-only. No Panel implementation is distributed in the plugin.
- `User::checkPermission` uses `Context::remember("users.{actor}.servers.{server}.{permission}")`. Native tests found stale same-process permissions after revocation; Access invalidates only this exact key and reloads the subuser relation before authorization. No global Gate replacement.
- Native ZIP importer and migrator were exercised against real SQLite migrations. Native installer always attempts `buildAssets`; failures are best effort for non-themes. Tests explicitly mock this optional subprocess unavailable. Plugin has no new frontend assets or composer dependencies.
- [Wings 7b489da](https://github.com/pelican/wings/tree/7b489da7b8196d0b4baef3e2db062c06d743e2f9): `router/router.go` server file routes use authorization and server existence; `router/router_server_files.go::postServerPullRemoteFile` returns 202 + identifier asynchronously, or 200 stat after `Execute` in foreground. Neither response is a file hash. `postServerWriteFile` does not share a maintenance lease with power or SFTP. `router/router_server.go::postServerPower` checks suspension for start/restart. `sftp/handler.go::can` checks suspended/protected state at permission evaluation; no demonstrated exclusive lease drains every open handle and authenticated API request. MIT; source only, no running node.
- [Eggs c6f2262](https://github.com/pelican-eggs/minecraft/tree/c6f2262eb351054d1be44d3f6a3a605ae163d682): inspected `java/paper/egg-paper.yaml` (Java images, `SERVER_JARFILE`, startup command), Fabric and Forge/NeoForge/proxy definitions as installation-layout references. MIT. No eggs imported, changed, or claimed startup-tested.

## Providers

- [MCJars source 2e96a20](https://github.com/mcjars/www/tree/2e96a20a398988706daf0dc7fd97ce7bb24b9280): MIT. `frontend/app/api/types.ts`, `backend/src/routes/api/v2/types.rs` define grouped actual type discovery. V3 `builds/types/{type}/versions`, version build pagination, `builds/{uuid}`, `models/{build,version,type}.rs` define immutable IDs, Java metadata and ordered `Vec<Vec<InstallationStep>>` containing download/unzip/remove. Bare v3 `/types` is not used. V2 statistics are not a catalog. Four metadata paths were live checked; coverage records all 28 discovered types without installability claims.
- [Modrinth API](https://docs.modrinth.com/api/) v2: public search, project/version ID binding, server-side metadata, files/hashes and dependency semantics. Search live checked; deterministic exact-release/schema fixtures tested. [Official mrpack format](https://support.modrinth.com/en/articles/8802351-modrinth-modpack-format-mrpack): exact runtime dependencies, server environment and overrides precedence. Parser/inspector utilities are connected to the preview.2 pack preparation and install worker; deterministic pack contracts are tested.
- [CurseForge API](https://docs.curseforge.com/rest-api/) v1: private `x-api-key`, game/category classes, project/file IDs, `allowModDistribution`, nullable download URL, `serverPackFileId`, hash algorithm IDs. Live authorized category and search tests confirmed game 432 and classes 5/6/4471. Credential injected only into the test process and stored encrypted temporarily; removed afterward. [Distribution field](https://docs.curseforge.com/docs/game-integration/unreal/browsing-and-discovery/) documents restrictions. No alternate CDN construction.
- Hangar, approved GitHub Releases, FTB and ATLauncher: no verified adapters delivered. They are not represented as installable integrations. Official access/distribution review and deterministic contract fixtures remain required before enabling them.

## Public feature references and license boundaries

| Source | Pin/license inspection | Observation and decision |
|---|---|---|
| [Official minecraft-modrinth](https://github.com/pelican/plugins/tree/194c1a39b8158b55c92cb1638e75cff51bdd4236/minecraft-modrinth) | `194c1a39b8158b55c92cb1638e75cff51bdd4236`; manifest and page/service inspected from local read-only upstream | Native page and repository integration reference. No code copied. Marketplace license label is not assumed to override repository/file terms. |
| [Modpack Manager](https://github.com/cosmii02/pelican-modpackmanager/tree/b87a267d0cf722799db5e4e56ed51179b4aab4c1) | `b87a267d0cf722799db5e4e56ed51179b4aab4c1`; MIT LICENSE read | README and native mod/pack page authorization, backup and installation inputs inspected. Advertised provider breadth does not prove required fences/recovery. No code copied. |
| [Pelican Mod Manager](https://github.com/kazaminosuke/pelican-mod-manager/tree/bcc35e9b8cf72365e403fb3ef7fd03a3a58cebee) | `bcc35e9b8cf72365e403fb3ef7fd03a3a58cebee`; GPL-3.0 LICENSE read | Provider registration, authorizer and installed archive transaction inspected at symbol/search level. Foreground pull plus file existence is not adopted as a full integrity/exclusion guarantee. No implementation copied. |
| [Minecraft Toolkit listing](https://hub.pelican.dev/plugins/minecrafttoolkit) | Product listing and restrictive license description | Public feature comparison only. No protected code/assets/integrations reused. |

## All three Blueprint variants (read-only)

- `iByte-MinecraftAdditions`: README, manifest/routes, unified TSX navigation, provider controllers, runtime selection, startup persistence and modpack download/stopped-state paths inspected. Preserve unified intent; reject raw SQL, unsafe fallback and bypassed stopped-state assumptions.
- `iByte-MinecraftAddonsInstaller`: README, routes/config, shared Mods/Modrinth/CurseForge controllers, pack install/upload paths inspected. Replace cache-only job ownership and direct node HTTP patterns with native durable review records. No file was changed.
- `iByte-MinecraftVersionChanger`: manifest/routes, MCJars types/version/build selectors and `pickDownloadUrl`/`install` paths inspected. No guessed type catalog, fallback build or installer-as-JAR behavior ported.

The original requirements source was AGENTS.md; the latest user instruction explicitly supersedes its exclusive-fence restriction (D009). Inspection of these files is not a license to reuse unrelated reference code. This plugin's original implementation is MIT.

## Preview.2 streaming verification

The official Modrinth service uses the native repository HTTP client for foreground file pulls. This plugin instead prepares verified files on Panel disk and uses that same authenticated client with a PSR stream body for files/write and stream=true for files/contents (download=1). Wings serves binary content through this endpoint. No nonexistent hash or exclusive-lock API is assumed. Actual artifact preparation and deterministic native transfer checks are recorded in TESTING.md; real Wings deployment remains unverified.


## Preview.9 native queue and scheduler investigation

[Pelican troubleshooting: schedules not running](https://pelican.dev/docs/guides/troubleshooting/#schedules-not-running) documents the existing every-minute artisan schedule:run entry and normal Panel worker. [Laravel scheduling](https://laravel.com/docs/12.x/scheduling) documents command scheduling, withoutOverlapping and runInBackground. Inspected native App Console Kernel, QueueWorkerServiceCommand, Laravel Schedule::command, Event and CommandBuilder in the pinned disposable checkout. The standard Panel service invokes queue:work without the plugin's separate queue; it does not consume that queue. The plugin now registers its own background command on the existing scheduler, preserving native service configuration. Actual Windows background scheduling and completed fixture installation are recorded in scheduler.json. No Linux/Redis/production execution is implied by source inspection.

## Preview.10 file protocol and client environment investigation

Observed source: pinned Wings server/filesystem/stat.go Stat.MarshalJSON emits file/directory/symlink. Native DaemonFileRepository::getDirectory returns this JSON directly; App Models File::getRows maps it into is_file/is_directory/is_symlink. The plugin must consume the raw response, not the mapped model. Test fixture flags were corrected accordingly.

Live public Modrinth metadata identifies FerriteCore uXXizFIs / d5ddUdiB with one artifact and no dependencies. Authorized read-only CurseForge investigation retrieved Odyssey project 434113 / release 6709034, its actual client archive and manifest, and identified seven non-JAR client entries. [Modrinth exact hash lookup](https://docs.modrinth.com/api/operations/versionfromhash/) provides the byte-hash version lookup used to establish environment; file size is also checked before consulting environment. No provider URL is guessed. See reported-provider-failures.json, live-reported-mod.json and reported-pack-preparation.json. The supplied credential is not stored in source or distribution; temporary fixture settings are restored.
# Direct Wings transfer investigation (preview.12)

Read [the official Modrinth plugin](https://github.com/pelican/plugins/tree/main/minecraft-modrinth), [the MIT modpack installer's moving-window implementation](https://github.com/cosmii02/pelican-modpackmanager/blob/main/src/Services/ModpackInstallService.php) and stock [Wings RC.1 file routes](https://github.com/pelican/wings/blob/v1.0.0-rc.1/router/router_server_files.go) / [downloader](https://github.com/pelican/wings/blob/v1.0.0-rc.1/router/downloader/downloader.go). The local official RC.1 source tag resolves to 7b489da7b8196d0b4baef3e2db062c06d743e2f9. Native Panel DaemonFileRepository::pull accepts filename and foreground, returning a download identifier for background requests. Wings GET files/pull supplies Identifier and Progress (0–1); DELETE files/pull/:id cancels a server-owned request. The default maximum is three concurrent pulls. Failed downloads disappear from the active list too, so disappearance alone cannot establish success. Wings requires Content-Length, applies its native network/redirect protections and provides no generic file checksum endpoint. We use size checks followed by bounded publisher-checksum readback and retain the existing transaction/recovery journal.

This supersedes the historical preview.2 Panel-upload design documented below. No third-party implementation is copied into the distribution, no GPL code is incorporated, and no Wings binary or core file is changed. Live direct-download evidence is kept separately from preview.11 acceptance so old results cannot substantiate the new transfer path.


## Direct destination and recovery research (preview.13)

- [Official Minecraft Modrinth plugin](https://github.com/pelican/plugins/tree/main/minecraft-modrinth): mods/plugins download to their native content directory.
- [Stock Wings RC.1 file router](https://github.com/pelican/wings/blob/v1.0.0-rc.1/router/router_server_files.go): scoped pulls, full-archive decompression, rename and delete; missing-source rename can return success, so destination hash verification remains required.
- [Stock Wings RC.1 extraction](https://github.com/pelican/wings/blob/v1.0.0-rc.1/server/filesystem/compress.go): archive entries extract relative to the supplied root; no per-entry selection API.
- [Stock Wings RC.1 backup router](https://github.com/pelican/wings/blob/v1.0.0-rc.1/router/router_server_backup.go): native restore processes an entire backup, unsuitable for restoring only selected files while preserving unrelated concurrent edits.

The pinned local RC.1 source was read alongside the public official plugin documentation. No third-party implementation was copied. Preview.13 uses temporary files in normal directories and privately stored old bytes rather than creating a hidden work directory in the server volume.
- [PostgreSQL JSON operators](https://www.postgresql.org/docs/current/functions-json.html): extracting display fields from the journal requires a JSON/JSONB value. The plugin casts its LONGTEXT journal explicitly on PostgreSQL. MySQL/MariaDB/PostgreSQL projection SQL is compiled in the regression suite; database execution is verified on SQLite, not those three engines.

Preview.16 rechecked Pelican's official Modrinth plugin (https://github.com/pelican/plugins/tree/main/minecraft-modrinth) for one-click latest-version installs and runtime filters, and Modrinth's project versions query parameters (https://docs.modrinth.com/api/operations/getprojectversions/). The implementation continues to use the existing provider adapters and stock Wings operations; no third-party implementation was copied.

Preview.17: inspected the native Laravel BladeCompiler::compileStatements directive boundary and current official provider contracts: https://docs.modrinth.com/api/operations/getprojectversions/ and https://docs.curseforge.com/rest-api/ . Modrinth's environment explanation (https://modrinth.com/news/article/new-environments/) distinguishes client-only and optional-server content; the actual v2 project metadata is checked in the live matrix. No experimental v3 API or copied third-party implementation is introduced.

Preview.25 bulk metadata: [CurseForge Core API Get Mods and Get Files](https://docs.curseforge.com/rest-api/) defines POST /v1/mods with modIds and POST /v1/mods/files with fileIds. [Official Modrinth OpenAPI](https://github.com/modrinth/docs/blob/master/static/openapi.yaml) defines POST /version_files hashes/algorithm and GET /projects ids. Native directory APIs are verified against the local official Panel DaemonFileRepository and authenticated loopback HTTP; no third-party implementation was copied.
