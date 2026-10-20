#!/bin/bash
# Query the Prometheus data source through Grafana's proxy to confirm dashboard queries work.
# Run inside the acceptance WSL distribution only.
set -u
base=/opt/ibyte-prometheus
pw=$(cat "$base/monitoring/secrets/grafana_admin_password")
curl -sS -u "admin:$pw" --get --data-urlencode 'query=ibyte_pelican_node_collection_success' \
  'http://127.0.0.1:3000/api/datasources/proxy/uid/ibyte-prometheus/api/v1/query' -o /tmp/grafana-query.json -w 'HTTP=%{http_code}'
echo
head -c 300 /tmp/grafana-query.json