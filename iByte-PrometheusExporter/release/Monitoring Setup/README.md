# Monitoring setup

The plugin exports **every node and game server** on the Panel in one bounded, token-protected endpoint: CPU, memory, cached game-data disk use, runtime state, per-node non-failed backup count/bytes and collection health.

## Existing Prometheus/Grafana

1. Install the plugin, open **Prometheus Exporter**, create a scrape key (shown once), and store it in your Prometheus bearer token file. Creating the key is what turns collection on.
2. Copy `prometheus.example.yml`'s scrape job into your existing Prometheus config. Replace `panel.example.com:443` with a host reachable **from Prometheus**, and put the key in the private file at `authorization.credentials_file`. Container `localhost` is the container, not the Panel host.
3. Validate with `promtool check config` and reload Prometheus by your normal procedure. Use ordinary trusted TLS; do not disable certificate verification. The plugin collects every five seconds; match or change the scrape interval as you prefer.
4. Import `dashboard.json` in Grafana and select your existing Prometheus data source in the import dialog. Choose a node/server and a Grafana time range to move through history. Nothing before the first successful scrape exists.

## Same-host Prometheus (skip proxies for the scrape)

If Prometheus runs on the Panel host itself, do not scrape through a public proxied hostname: a challenge-issuing proxy (e.g. an incorrectly scoped CDN rule) will reject non-browser scrapes and the target will stay `up == 0`. Either pin the hostname to the origin in `/etc/hosts` (TLS and the Host header stay intact), or add a loopback-only HTTP vhost for the metrics path and scrape `localhost:<port>` over `http`. Loopback HTTP is an acceptable same-host exception: the bearer key never leaves the machine. For any scraper on a different host, keep HTTPS with normal verification and no `insecure_skip_verify`.

Confirmed same-host recipe (production-tested 2026-10-20): pin `panel.<domain> -> 127.0.0.1` in `/etc/hosts`, scrape target `panel.<domain>:443` over `https` with `authorization.credentials_file`. If the origin vhost geo-fences by the CDN country header, note two gotchas: (1) a plain `https://127.0.0.1` curl test fails SNI routing to the catch-all vhost — always test with `curl --resolve panel.<domain>:443:127.0.0.1` so SNI matches the real vhost; (2) requests directly from the origin have no CDN country header, so a `map $http_cf_ipcountry $allowed_country { default 0; ... }` block rejects them — exempt loopback explicitly (add `127.0.0.1 1;` and `::1 1;` to the map). The plugin endpoint itself is the security boundary: missing/wrong key is 401 (`hash_equals` against the stored SHA-256), and nginx needs no extra ACL for it.

## Optional fresh stack (Linux)

In this folder, run `PANEL_TARGET=panel.example.com:443 ./setup.sh`; it prompts for the one-time Panel scrape key. Set `PROMETHEUS_RETENTION=60d` first if desired. The script creates private local secrets and config only on the first run, validates Compose and Prometheus config, then starts Prometheus and Grafana. Run the same command again to repeat safely without overwriting secrets, config, or named data volumes. It binds both web ports to `127.0.0.1`; use your own secure access method for remote operators. Read `secrets/grafana_admin_password` locally and log in as `admin`. In Grafana, add Prometheus at `http://prometheus:9090`, then import the dashboard and choose that data source.

To stop, run `docker compose down`. Do **not** add `-v` unless you intentionally want to delete history. To rotate the scrape key, rotate it in Panel, replace `secrets/pelican_scrape_token`, and restart Prometheus. The optional images have exact version tags and Docker Hub manifest digests, but have not been runtime validated here; this is a preview release gate.

Sizing: Prometheus retains 30 days by default, subject to available disk. Five-second scrape yields 17,280 samples/day per emitted series, before index and WAL overhead. Allocate disk based on your server count and measure actual growth. No Loki is needed; true console history is not offered.
