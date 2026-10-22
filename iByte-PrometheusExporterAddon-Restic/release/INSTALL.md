# Install — iByte Prometheus Exporter Restic Add-on

**Artifact:** `ibyte-prometheus-exporter-addon-restic-0.1.2.zip`
**SHA-256:** `65E75E8A5B294B9CB8AE9865A2350F3BAEC257949D5700B40100D5EED5712AF0`

> This build appends the Restic families to the base exporter's existing `/ibyte-prometheus-exporter/metrics` scrape — no extra Prometheus job needed. The standalone `/ibyte-prometheus-exporter-addon-restic/metrics` endpoint also still answers with the same data. Requires base exporter ≥ 0.3.1 (the rc.2-retargeted 0.3.2 works identically).

## Pre-checks (all must be true)

1. Base plugin `ibyte-prometheus-exporter` **installed and Enabled**, with a working scrape key (its own metrics endpoint already returns 200 for your Prometheus).
2. Plugin `ibyte-restic` (3.0.0) installed with tables finished, and at least one repository with a saved storage measurement (visible in **Admin > Restic Backups > Stats**).
3. Same Pelican Panel version as the base exporter targets (1.0.0-rc.2).

## Install

Admin > Plugins > Import > upload `ibyte-prometheus-exporter-addon-restic-0.1.2.zip` > Install. Then re-enable if importer leaves it disabled.

## Verify (one minute)

```bash
TOKEN=$(sudo cat /etc/prometheus/secrets/pelican_scrape_token)
curl -sS -H "Authorization: Bearer $TOKEN" \
  https://panel.example.com/ibyte-prometheus-exporter-addon-restic/metrics | head -20
```

Expect: 200, `ibyte_pelican_restic_addon_success 1`, one `repo_info` line per current repository, byte/ratio families matching the Stats page.

401 → base exporter key mismatch or base exporter disabled.
`addon_success 0` → collection round failed (check Panel log `storage/logs/laravel.log` for `ibyte:prometheus-collect-restic`).

## Second scrape job (operator Prometheus)

```yaml
  - job_name: pelican_ibyte_restic
    scheme: https
    metrics_path: /ibyte-prometheus-exporter-addon-restic/metrics
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/pelican_scrape_token
    static_configs:
      - targets: ['panel.example.com:443']
```

`systemctl restart prometheus` then confirm `up{job="pelican_ibyte_restic"} == 1`.