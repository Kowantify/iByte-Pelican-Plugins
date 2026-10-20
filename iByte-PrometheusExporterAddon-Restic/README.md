# iByte Prometheus Exporter — Restic Add-on

Exposes the storage statistics that **Admin > Restic Backups > Stats** already shows (repository files, logical restore data, dedup estimate, compression, snapshots, measured-at) as extra Prometheus metric families.

## Requirements

- **The base plugin `ibyte-prometheus-exporter` must be installed and enabled first.** This add-on is inert without it: nothing collects, the endpoint answers 401, and no metrics are exposed.
- iByte Restic plugin (`ibyte-restic`) with its tables installed and storage measurements populated.
- Works with the same Pelican Panel version as the base exporter (1.0.0-rc.1).

## Install

1. Install/enable `ibyte-prometheus-exporter` and create a scrape key on its admin page (you already did this if its metrics endpoint works).
2. Import this add-on's ZIP through **Admin > Plugins > Import**, same as any plugin.
3. Add a second scrape job to the operator's Prometheus:

```yaml
  - job_name: pelican_ibyte_restic
    scheme: https
    metrics_path: /ibyte-prometheus-exporter-addon-restic/metrics
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/pelican_scrape_token   # the SAME token file as the base exporter
    static_configs:
      - targets: ['panel.example.com:443']
```

No new key, no new admin page, no configuration. Revoke the key on the base exporter's page and this add-on stops serving too.

## Metric families

All gauges, labels `panel_id`, `node_id`, `server_uuid` (identity details on `ibyte_pelican_restic_repo_info` only):

| Family (suffix after `ibyte_pelican_restic_`) | Meaning |
|---|---|
| `repo_info` | 1 with `server_name`, `owner`, `owner_id`, `host_id`, `backend`, `profile`, `generation` |
| `addon_success` | 1 = this add-on's latest collection round succeeded (no server labels) |
| `repo_file_bytes` | On-disk repository file lengths (metadata + unpruned packs included; not allocated blocks or cloud billing) |
| `repo_files_measured` | 1 when a file measurement exists (0 for remote backends where it is unavailable) |
| `repo_blobs_compressed_bytes` | Unique compressed blob bytes referenced |
| `repo_blobs_uncompressed_bytes` | Unique uncompressed blob bytes referenced |
| `repo_logical_bytes` | Total logical restore bytes across snapshots |
| `repo_snapshots` | Snapshot count in the latest measurement |
| `repo_compression_ratio` | Uncompressed / compressed blob bytes |
| `repo_compression_space_saving` | Fraction saved by compression (0.425 = 42.5%) |
| `repo_measured_timestamp_seconds` | Unix time of the latest saved measurement |

Missing/invalid measurements are omitted, never rendered as zero — same honesty as the admin page.

## Handy PromQL

```promql
# Storage by server as the table shows it
topk(5, ibyte_pelican_restic_repo_file_bytes * on(panel_id, server_uuid) group_left(server_name, owner) ibyte_pelican_restic_repo_info)

# Dedup estimate
ibyte_pelican_restic_repo_logical_bytes / clamp_min(ibyte_pelican_restic_repo_blobs_uncompressed_bytes, 1)
```

Example query matching the "Storage by server" admin table:

```promql
topk(5, ibyte_pelican_restic_repo_file_bytes * on(panel_id, server_uuid, node_id) group_left(server_name, owner) ibyte_pelican_restic_repo_info)
```