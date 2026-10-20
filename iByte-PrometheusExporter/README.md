# iByte Prometheus Exporter for Pelican

Native Pelican Panel plugin exposing one bearer-key-protected `/metrics` endpoint that Prometheus scrapes for CPU, memory, cached game-data disk use, runtime state, per-node backup totals and collection health for **every node and game server** on the Panel. Grafana reads Prometheus. Console logs are outside this release.

Built entirely through Pelican's plugin system (manifest, Filament page, service providers, a route and one scheduled command) — no core edits, no migrations, no Wings changes. The admin page does one job: issue/rotate/revoke the read-only scrape key; an active key is what enables collection. The operator configures their own Prometheus against `https://<panel>/ibyte-prometheus-exporter/metrics` (example in `release/Monitoring Setup/`).

Targets Panel v1.0.0-rc.1 and Wings v1.0.0-rc.1 (commits in `compatibility/versions.json`). Verified end-to-end in a disposable WSL2 acceptance fixture — native ZIP import/install through the panel's own plugin service, real Wings collection on two HTTPS nodes, key rotation/revocation, promtool-clean exposition, samples in Prometheus with `name`/`owner` labels — and running in production on the operator's panel. Remaining accepted-risk gates (scheduler persistence across restarts, multi-host cache, TLS matrix, Grafana browser walkthrough) are listed in `docs/PROGRESS.md`.

Install: `release/Panel Plugin/INSTALL.md`. Monitoring examples: `release/Monitoring Setup/README.md`.

Metrics are current only while the Panel scheduler polls Wings and Prometheus scrapes the protected endpoint. Scrape results are retained per Prometheus retention. Collection and scrape cadence is five seconds; Grafana refresh is five seconds. Wings disk cache has independent, unknown freshness.
