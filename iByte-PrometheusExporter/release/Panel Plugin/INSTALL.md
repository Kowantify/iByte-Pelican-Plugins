# Install the plugin

1. In Panel Admin > Plugins > Import, import `ibyte-prometheus-exporter-0.3.2.zip`
   (SHA-256 `C3F6B2309A494C3EAF773F9271B38AAF0878C82C2D43D07DF167B93359723737`),
   then install and enable it. Nothing else is installed on the Panel: no core edits,
   no migrations, no commands beyond the plugin's own scheduled collector. 0.3.2 is the
   same code as 0.3.1 retargeted to Panel 1.0.0-rc.2 — nothing else changed.
2. Open **Prometheus Exporter** in the administrator sidebar and create a scrape key.
   Store that key once in a private file on your Prometheus host and point the scrape
   job's `authorization.credentials_file` at it. Creating or rotating a key enables
   collection; revoking it stops collection and rejects scrapes immediately.
3. Set up Prometheus yourself to scrape `https://<panel-host>/ibyte-prometheus-exporter/metrics`
   with that key as the bearer token. `Monitoring Setup/prometheus.example.yml` is a
   working example — validate with `promtool check config`, then reload Prometheus your way.
4. Confirm the ordinary Panel scheduler runs `php artisan schedule:run` every minute.
   Laravel sub-minute scheduling keeps the plugin's collector active through the minute;
   a shared lock-capable cache and shared plugin storage are required for multi-host Panels.
5. Import `Monitoring Setup/dashboard.json` into Grafana and select your Prometheus data source.
   Node health after setup is visible on that dashboard's node panels.

The plugin polls every node's stock HTTPS Wings API every five seconds while a key is
active and caches CPU, memory, game-data disk, state, per-node backup totals and collection
health for all game servers. The metrics endpoint serves that cache without calling Wings,
so Prometheus scrapes never multiply load. The admin page does exactly one job: issue,
rotate or revoke the scrape key.

The plugin has no SQL migrations or Wings changes. Disabling the plugin stops its route
and collection; uninstalling it does not delete Prometheus/Grafana history. Settings and
cached snapshots live in Panel storage/cache and are not automatically erased.

Updating from 0.1.x/preview: import the new ZIP and re-enable. Backup metrics were renamed
(`ibyte_pelican_node_backups_stored`/`_bytes` replace `..._backup_count`/`..._backup_bytes`),
`ibyte_pelican_exporter_enabled` became `ibyte_pelican_exporter_key_active`, and a `server_info`
series now adds `name`/`owner` labels. Re-import the bundled dashboard.

Updating from 0.3.1 (Panel rc.1): import over the installed plugin and reinstall (same ID,
nothing deleted). On Panel 1.0.0-rc.2, note the panel-side startup issue: rc.2 evaluates
plugin manifests during provider registration while Laravel's cache manager is still
unresolvable; until upstream Pelican fixes it, that is handled by a Panel-side guard, not by
this plugin.
