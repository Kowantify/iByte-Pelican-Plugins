# Metric meanings and limits

All metrics are Prometheus gauges. Labels are `panel_id`, `node_id` and `server_uuid`; `ibyte_pelican_server_state` adds `state`, and `ibyte_pelican_server_info` adds `name` and `owner`. Names, owners, environment variables, node URLs and tokens are never labels beyond those. Deleted/transferred servers disappear from the current endpoint; Prometheus keeps previous samples until its retention expires.

| Metric suffix after `ibyte_pelican_` | Meaning |
|---|---|
| `server_cpu_usage_ratio` | `Wings utilization.cpu_absolute / 100`: 1.0 is one logical core and values can exceed 1.0. |
| `server_memory_usage_bytes` | Wings Docker memory usage after inactive-file adjustment where available. |
| `server_disk_usage_bytes` | Wings' cached game-data directory usage or quota usage. It is not host free space, total volume usage, or a guaranteed fresh disk scan. Backup storage outside that directory is excluded; mounts and quota modes need runtime verification. |
| `server_cpu_limit_cores`, `server_memory_limit_bytes`, `server_disk_limit_bytes` | Panel-configured allocations converted from percent of one core or MiB. An unlimited allocation has no limit series. |
| `server_cpu_unlimited`, `server_memory_unlimited`, `server_disk_unlimited` | 1 if Panel allocation is zero/unlimited, else 0. |
| `server_state` | One series with value 1 and a state label such as running, offline or suspended. Unknown states use `unknown`. |
| `server_info` | One series with value 1 per server carrying the Panel display `name` and `owner` username labels. Renames or ownership changes change the label set; Prometheus keeps prior samples until retention expires. |
| `server_network_rx_bytes`, `server_network_tx_bytes` | Bytes received/sent by the container since container start (Wings utilization counters; reset on restart). |
| `server_disk_read_bytes`, `server_disk_write_bytes` | Bytes read/written by the container since start (Wings disk-io counters; use `rate()` for per-second). |
| `server_uptime_seconds` | Container uptime in seconds from Wings; restarts reset it. |
| `server_wings_memory_limit_bytes` | Wings-side per-process memory ceiling (OOM target), separate from the Panel allocation. |
| `node_memory_capacity_bytes`, `node_disk_capacity_bytes`, `node_cpu_capacity_cores` | Node-level configured capacity the Panel allocates against. Null when the node value is zero/unlimited. |
| `node_memory_overallocate_percent`, `node_disk_overallocate_percent`, `node_cpu_overallocate_percent` | Panel configured over-allocation percentages per node. |
| `node_allocations`, `node_allocations_used` | Allocations configured on the node and assigned to a server. |
| `node_servers_assigned` | Servers assigned to the node in Panel inventory. |
| `node_servers_status` | Servers on the node grouped by Panel status; one series per `status` label (installing, install_failed, reinstall_failed, suspended, restoring_backup). |
| `panel_users`, `panel_root_admins`, `panel_nodes`, `panel_nodes_maintenance`, `panel_servers`, `panel_allocations`, `panel_allocations_used`, `panel_eggs` | Panel-wide inventory counts (gauge names avoid the reserved `_total` suffix). |
| `panel_servers_status` | Panel servers grouped by `status` label (suspended, installing, install_failed). |
| `node_backups_stored` | Count of non-failed panel backups stored on the node (completed or in-flight, using the panel's `nonFailed` definition). |
| `node_backups_bytes` | Total bytes of those non-failed backups. |
| `node_collection_success` | 1 only when the latest poll succeeded within the 15-second freshness grace (three fixed 5-second collection intervals). |
| `node_last_success_timestamp_seconds` | Time the Panel completed the latest successful API poll, not source sample time. |
| `node_collection_age_seconds` | Age of latest poll attempt. |
| `node_consecutive_failures` | Number of failed polls since the latest success in Panel cache. |
| `node_collection_duration_seconds` | Elapsed batch time through node response processing. |

Never exported: server environment variables, egg metadata/mounts/allocation IPs/ports, daemon tokens, user emails, node descriptions or raw configuration. All exported names are validated by `tests/normalizer.php` and `promtool check metrics` in the fixture runs.

A failed/stale node omits its server usage series and leaves health series visible. Prometheus therefore shows a gap rather than a replayed zero. Scraping unchanged cache more often does not create a fresh Wings measurement. The default Prometheus scrape interval and Grafana refresh are both five seconds, independently configurable outside the plugin. Wings provides no disk source timestamp in this API.
