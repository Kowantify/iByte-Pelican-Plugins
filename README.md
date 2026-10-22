# iByte Pelican Plugins

Official plugins for the [Pelican Panel](https://pelican.dev) by **iByte Hosting**.

Five native panel plugins, one repository. Every current Panel package is
version 2.0.0 and uses `ibyte-<plugin-id>-v2.0.0.zip`. Install through
**Admin > Plugins > Import > Install**; each project carries its own README,
license and compatibility metadata.

| Folder | Plugin | Version | What it does |
|---|---|---|---|
| [`iByte-PrometheusExporter`](iByte-PrometheusExporter/) | iByte Prometheus Exporter | 2.0.0 | One bearer-key `/metrics` endpoint with CPU, memory, disk, state, backup and panel metrics for every node and game server; Grafana dashboard included |
| [`iByte-PrometheusExporterAddon-Restic`](iByte-PrometheusExporterAddon-Restic/) | Restic Add-on | 2.0.0 | Appends iByte-Restic repository storage metrics to the exporter's scrape automatically |
| [`iByte-Restic`](iByte-Restic/) | iByte Restic Backups | 2.0.0 (Panel) | Native Restic backup-host schema for panel + Wings: retention, export, recovery vault, guarded deletion |
| [`iByte-MinecraftManager`](iByte-MinecraftManager/) | iByte Minecraft Manager | 2.0.0 | Install Minecraft software, mods, plugins and modpacks from Modrinth/CurseForge/MCJars through stock Wings |
| [`iByte-EggChanger`](iByte-EggChanger/) | iByte Egg Changer | 2.0.0 | Resource-gated egg switching: admins define RAM/disk packages, owners run backup-first clean reinstall switches |

## Install (any plugin)

1. Open **Admin > Plugins > Import** and upload the plugin's ZIP from its
   `release/Panel Plugin/` folder.
2. Press **Install**. The queue worker applies migrations and enables it.
3. Each plugin's README covers first-run setup.

## Requirements (all plugins)

- Pelican Panel version and compatibility details are recorded separately for each plugin in its manifest and `compatibility/versions.json`.
- PHP 8.4+ (8.5 used in production), Laravel 13
- A running queue worker (`php artisan queue:work`) for background jobs

## Security

Every admin surface is root-admin gated. Client-area pages are owner-only or
native-permission gated. Metrics endpoints use one bearer key verified with
`hash_equals`. AI/agent working files (AGENTS.md etc.) are intentionally kept
out of this repository.

## License

MIT © iByte Hosting — each plugin folder carries the same license, and each
respects its own upstream dependency licenses (documented per folder).
