#!/bin/bash
# Validate the captured live exposition with promtool inside the monitoring container.
set -u
[[ -s /tmp/live-metrics.txt ]] || { echo 'No exposition captured yet' >&2; exit 1; }
docker exec -i monitoring-prometheus-1 promtool check metrics < /tmp/live-metrics.txt
echo "PROMTOOL_EXIT=$?"