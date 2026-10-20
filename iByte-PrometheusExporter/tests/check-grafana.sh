#!/bin/bash
# Read-only Grafana checks for the disposable acceptance fixture (health, data source, dashboard).
# Run inside the acceptance WSL distribution only.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
base=/opt/ibyte-prometheus
pw=$(cat "$base/monitoring/secrets/grafana_admin_password")
curl -s -u "admin:$pw" http://127.0.0.1:3000/api/health; echo
curl -s -u "admin:$pw" http://127.0.0.1:3000/api/datasources/uid/ibyte-prometheus | head -c 200; echo
curl -s -u "admin:$pw" http://127.0.0.1:3000/api/dashboards/uid/ibyte-pelican-resources |
  python3 -c 'import json,sys; d=json.load(sys.stdin); print("Dashboard:", d.get("dashboard", {}).get("title", d))'
curl -s -u "admin:$pw" --get --data-urlencode 'query=ibyte_pelican_node_collection_success' \
  http://127.0.0.1:3000/api/datasources/proxy/uid/ibyte-prometheus/api/v1/query | head -c 250; echo