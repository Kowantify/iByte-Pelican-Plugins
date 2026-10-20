# UI installation repair: preview.4

**Correction after the preview.5 production report:** new migration paths bypass a cached migration only when the old migration disappears from discovery. Published older importers retain omitted files or derive a nested destination from versioned ZIP filenames. Nine upstream-importer checks reproduce preview.5 metadata alongside the original failure; published RC.1 replacement succeeds in the same pre-failed process. The production importer/retained-file condition remains unverified. See [INSTALLER-INVESTIGATION.md](INSTALLER-INVESTIGATION.md). The UI evidence below does not establish universal production recovery.

Preview.2 could fail with `Class IByte\MinecraftManager\Support\SchemaInstaller not found` when Pelican's installer worker had not registered the newly imported plugin namespace. The original integration fixture explicitly registered that namespace and masked the defect. This was reproduced independently before the fix.

Preview.3 removes plugin-class dependencies from the migration. Both migrations use native Laravel classes; initial table creation loads a shared package-local schema closure by filesystem path. Admin schema repair uses that same definition. Existing records are retained. No Panel core or Wings edits.


Preview.4 also handles a worker that already failed on preview.2: Laravel caches anonymous migration objects by file path, so preview.3 could still run the old cached object. New ordered migration paths bypass that cache without restarting the worker. The repeated-failure case was reproduced in the same native Migrator process, then verified through the real Panel UI with that original failure primed in the worker. Existing tables and records are preserved.

## Normal UI update/install

Import preview.4 through Admin → Plugins → Import from File. Use the native Install action if the plugin is Not Installed; imports over an installed plugin are reinstalled by Pelican's normal queue. No composer dump-autoload, manual migration patch or custom namespace registration is needed for this fix. Keep the normal Panel installation queue running. Restart workers normally after completing plugin updates so runtime code refreshes.

## If the Panel itself cannot render

`public/build/manifest.json` is a core Panel asset manifest. Pelican's native plugin installer invokes `yarn install` and `yarn build` before migrations, even for a plugin without its own assets. A missing manifest establishes that assets are unavailable; it does not identify the earlier build error. A failed native build may leave assets unavailable. The plugin ZIP does not ship or replace Panel core assets, and cannot repair a Panel page that cannot render.

Restore the matching Panel's built assets from your known-good deployment, or rebuild from the installed Panel directory using its existing lockfile:

```sh
cd /var/www/pelican
yarn install --frozen-lockfile
yarn build
```

Run with the deployment account and supported Node/Yarn for that installed Panel release. If the build fails, its first error is the actionable cause; do not repeatedly retry plugin installation or run composer update. The [official Panel update guide](https://pelican.dev/docs/panel/update/) documents asset rebuilding. No database, worlds, plugins, Wings files or application key need deletion for this repair.

## Actual verification

- An isolated SQLite migration process with no plugin autoloader reproduced the original class-not-found and now passes five checks: helper unavailable, all tables created, repeat preserves data, uninstall retains data, helper still unavailable.
- The ZIP was uploaded through the actual native Filament FileUpload UI and its Import and Install table actions were clicked in Edge.
- A real native database queue worker was started before import. Plugin loading was disabled in that worker only to ensure no plugin namespace/helper was available. It ran the actual native InstallPlugin job, real Yarn dependency install/build, both migrations and enabled the plugin. No Process fake or manual namespace registration was used for this UI run.
- A separate probe verified four tables, both migration records, Enabled status, a newly generated Vite manifest and all referenced built asset files.
- Environment remains the isolated Windows/SQLite Panel canary fixture. This verifies that UI installation path here, not the undisclosed cause of a production host's earlier Yarn failure. No real Wings game startup is claimed.
