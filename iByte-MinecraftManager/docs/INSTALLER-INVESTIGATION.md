# Repeated SchemaInstaller failure investigation

## Subsequent sidebar/mapping report: reproduced OPcache packaging defect

The latest screenshot shows the preview.4 mapping instructions and Save mapping label, although preview.5 is reported installed. Every previous ZIP used the same 1980 timestamp for every PHP file. PHP's OPcache timestamp validation can therefore keep old classes and translation arrays after importing updated bytes. This affects ordinary web requests, independently of Laravel's migration object cache.

Three checks using a real persistent PHP HTTP server with OPcache enabled reproduce changed source executing old code across requests with the same mtime, then refreshing with a different mtime. A native-plugin upgrade sequence warms preview.4's actual PHP/translation files, imports preview.5 through the native importer and observes exactly the screenshot's old instructions, then imports preview.6 through the actual UI and observes the new label/class without restarting the HTTP server. Results: evidence/opcache-upgrade.json and native-opcache-upgrade.json. This reproduces a concrete defect in our distribution; the production host's OPcache configuration is not inspected.

Preview.6 uses a fixed timestamp unique to the release, retained in scripts/release-dates.json and package provenance. This keeps ZIP output reproducible while allowing standard timestamp validation to notice upgrades. It does not reset global OPcache, modify Panel/Wings or refresh classes already loaded within a long-lived queue process. Deployments that disable timestamp validation still require their normal PHP-worker refresh. Admin mapping feedback now shows the saved egg, IDs, affected server links, missing matching servers and global-disable warnings. Seventeen OPcache-enabled native browser checks pass, including actual server Console sidebar visibility after mapping. Production acceptance remains pending.

The user confirmed Panel `1.0.0-rc.1` and Minecraft Manager `0.1.0-preview.5`. The supplied production stack records execution of `database/migrations/2026_10_16_000001_create_minecraft_manager_tables.php:10` through the native InstallPlugin queue job. This does not establish a fresh failure after importing preview.5; see the timestamp comparison below.

## Timestamp comparison after RC.1 confirmation

The supplied error is dated `2026-10-17 02:35:49`. The final delivered preview.5 ZIP was created locally at `2026-10-17 03:05:32 UTC` (October 16, 10:05:32 p.m. America/Chicago). RC.1's official config/app.php sets timezone UTC. Under that setting, the supplied error occurred 29 minutes 43 seconds before the delivered preview.5 package existed. Preview.4 was also packaged later, at 02:37:59 UTC. Local creation timestamps are evidence from this workspace, not a signed build timestamp or proof of the production log viewer's timezone.

This supports a historical-error explanation and contradicts treating that entry alone as a verified new preview.5 failure. No fresh post-update failure or production-log timezone override has been supplied. Confirm current native plugin status and the timestamp of a newly failed Install action before further source changes. No need to delete historical logs, plugin data or queue jobs.

## Original defect

Preview.2's migration calls a plugin class. A worker booted before import can lack the namespace registration, so the call fails. The original integration test manually registered that namespace and masked the defect. This was a plugin defect, unrelated to CurseForge, Wings or egg settings. The inspected sibling Restic initial migration directly uses native Laravel schema classes. The official Modrinth plugin currently has no database migration directory and does not exercise this dependency.

The immutable preview.5 ZIP (SHA-256 a2afa2f6a46b1aa62877158176eb37424fcc67850c305b29961cbcddee0e7a4c) contains only the two `2026_10_17_...` migrations, neither calling SchemaInstaller. The reported code is legacy code despite the visible preview.5 manifest. A manifest version does not identify retained files or previously resolved migration objects.

## Published installer research

Read official [RC.1 PluginService](https://github.com/pelican/panel/blob/v1.0.0-rc.1/app/Services/Helpers/PluginService.php), [beta35 PluginService](https://github.com/pelican/panel/blob/v1.0.0-beta35/app/Services/Helpers/PluginService.php), beta33/beta34/beta36/beta37/beta38 sources and [official Modrinth](https://github.com/pelican/plugins/tree/main/minecraft-modrinth).

RC.1 derives the target from plugin.json and replaces the directory. Using its published service, reproduced the original failure, imported the versioned preview.5 ZIP, verified the old migration was removed and ran the new migrations in the same process without helper autoload. This passes.

Beta35 derives the target from the uploaded filename and normally overlays files. A versioned filename nests the plugin incorrectly and leaves the original untouched. A canonical filename updates the manifest to preview.5 but retains omitted migrations. That retained original produces the reported SchemaInstaller error. Beta36 source retains this behavior; beta37 uses replacement. This is a confirmed reproduction, not proof that production uses that importer.

Laravel's static anonymous-migration cache is a separate confirmed mechanism. Overwriting a path can leave its resolved old object active. New paths bypass it only if the old file disappears from migration discovery. Previous preview.4 recovery claims omitted that condition.

## Evidence and limits

`php scripts/research-importers.php`: nine passing checks using published service method bodies, isolated only by namespace so both versions coexist. Uses native fixture dependencies, disposable directories and in-memory SQLite. No installed Panel/plugin files, production database, core or Wings modifications. No UI/Yarn build in this research script. Source hashes/results: evidence/importer-research.json and importer-research.log. Earlier actual UI evidence remains specific to the newer fixture importer.

Production RC.1 is confirmed by the user; its migration-directory listing, worker code identity and filesystem view are unavailable. The older-importer reproduction does not explain the confirmed RC.1 installation. RC.1 replacement does not reproduce the legacy path after update. If a fresh failure is established, retained legacy files, an import that did not replace the active directory, or a differing worker/filesystem view would need evidence. Do not claim a current production failure or issue a speculative ZIP from the historical entry alone.
