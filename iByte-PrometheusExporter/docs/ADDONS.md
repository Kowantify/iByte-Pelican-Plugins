# Add-ons — iByte Prometheus Exporter

A small guide to how the add-on system for the base plugin ("iByte Prometheus Exporter") works: what an add-on is, how it plugs into the main metrics scrape, and how to write one.

## 1. What an add-on is

An add-on is an **independent, installable Pelican plugin** that extends the base exporter's exposition with additional metric families. It exists as its own plugin (`plugin.json`, own namespace, own ZIP, installed through Admin > Plugins > Import) so base/add-on versioning stays independent — but it does nothing alone: the base exporter must be **installed and Enabled** for an add-on to collect or expose anything.

Current add-ons:

| Plugin ID | What it adds | Source of data |
|---|---|---|
| `ibyte-prometheus-exporter-addon-restic` | `ibyte_pelican_restic_repo_*` families + `..._addon_success` | iByte Restic's own tables (read-only) |

## 2. How the plumbing works

**Merged scrape (automatic).** The base exporter's scrape endpoint — the one your Prometheus job already scrapes — fires a `MetricsRendered` event right before responding. Every add-on listens to that event and appends its own pre-rendered exposition lines. The result: you install an add-on, and **the same job, same key, same URL** starts returning the new metric families. No extra Prometheus config.

**Standalone endpoint (optional).** The add-on can also serve a route of its own — `/ibyte-prometheus-exporter-addon-restic/metrics` — for anyone who wants to scrape add-on data into a separate job. Same bearer key, same content. Unused unless you set a job for it.

**Security.** Add-ons reuse the base exporter's scrape key: there is exactly one key in the deployment, stored hashed by the base, verified with `hash_equals`. Revoking the key on the base exporter's page stops everything — base metrics and every add-on. Add-ons never get their own credentials.

**Safety gate:** `MetricsRendered::append()` validates every appended line — must have the `ibyte_pelican_` prefix, a valid exposition shape and a numeric value (or be an appropriately-prefixed `# HELP`/`# TYPE` line). A broken add-on can fail to contribute at all, but cannot corrupt or fabricate base families.

**Lifecycle gate:** every add-on re-checks the base exporter is present and `Enabled` at every entry point (`Support/ParentExporter::active()`), and reuses the base's `panel_id` and token-hash settings file. Parent disabled or keyless → the add-on goes inert silently.

**Collection.** Each add-on registers its own five-second scheduled artisan command (mirroring `ibyte:prometheus-collect`), reads its own upstream, and caches one snapshot. Scrapes are always served from cache — add-ons never turn your scrape into extra load.

## 3. What the endpoint looks like

```text
# HELP ibyte_pelican_node_info ...                <- base families (unchanged)
# TYPE ibyte_pelican_node_info gauge
ibyte_pelican_node_info{...} 1
   ...
# HELP ibyte_pelican_restic_repo_info ...         <- addon families (appended)
# TYPE ibyte_pelican_restic_repo_info gauge
ibyte_pelican_restic_repo_info{...} 1
ibyte_pelican_restic_repo_file_bytes{...} 3612678263
   ...
ibyte_pelican_restic_addon_success{...} 1         <- addon self-health, always last
```

## 4. Writing a new add-on (checklist)

The Restic add-on under `iByte-PrometheusExporterAddon-Restic/` is the reference implementation; copy its shapes:

1. **`plugin.json`** — new id (`ibyte-prometheus-exporter-addon-<thing>`), own namespace, `panel_version 1.0.0-rc.1`, `api_version 1`, no `database/` dir.
2. **`src/Providers/<Thing>Provider.php`** — auto-discovered by Pelican. In `boot()`:
   - schedule your collector (`everyFiveSeconds()->withoutOverlapping(1)->onOneServer()`),
   - `Event::listen(MetricsRendered::class, ...)` and append your lines via `$event->append()`.
3. **Collector command** (`src/Console/...`): lock `Cache::lock(...)`, gate on `ParentExporter::active()` and a non-empty token hash, read upstream, whitelist fields, `Cache::put('ibyte-prometheus-addon-<thing>:latest', $snapshot, 600)`.
4. **Optional standalone route** for a separate scrape job, or skip it if you only want merged rendering.
5. **Families** must already exist as rendered facts in the source product's admin UI — omit unknown values, never fake zeros (this mirrors the base exporter's honesty rules).

## 5. Versioning and packaging rules

- Bump each plugin's own `plugin.json` version independently; base releases stay additive with respect to add-ons (the event is append-only by contract).
- Package with `scripts/package.ps1`-style forward-slash ZIP entries — `Compress-Archive` produces entry names Pelican's importer cannot parse.
- After any repackage: update the project's `compatibility/versions.json` (base) and the add-on's `release/INSTALL.md` (hash), plus the maintained agent-guidance file if behavior changes (that file is intentionally kept out of the published repo).
- **Never import an unlinted plugin**: run `php -l` on every packaged PHP file (remotely is fine: `find . -name "*.php" -exec php -l {} \;`) before import — a provider whose parent class fails to resolve takes the whole panel down with a 500.