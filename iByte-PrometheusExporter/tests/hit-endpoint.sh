#!/bin/bash
# Hit the panel metrics endpoint like Prometheus would (bearer key, HTTPS).
# Read-only; run inside the acceptance WSL distribution only.
set -u
key=$(cat /opt/ibyte-prometheus/scrape-token)
curl -sk -H "Authorization: Bearer $key" \
  https://panel.fixture.test:8443/ibyte-prometheus-exporter/metrics