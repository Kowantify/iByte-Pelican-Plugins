#!/bin/bash
set -euo pipefail
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || exit 1
project=$(cd "$(dirname "$0")/.." && pwd)
base=/opt/ibyte-prometheus
mkdir -p "$base/monitoring"
cp "$project/release/Monitoring Setup/"* "$base/monitoring/"
cd "$base/monitoring"
cat > compose.override.yaml <<'EOF'
services:
  prometheus:
    extra_hosts:
      - panel.fixture.test:host-gateway
    volumes:
      - /opt/ibyte-prometheus/tls.crt:/etc/ssl/certs/ibyte-fixture.crt:ro
EOF
PANEL_TARGET=panel.fixture.test:8443 PANEL_TOKEN=$(cat "$base/scrape-token") sh setup.sh
sha256sum prometheus.yml secrets/* .env > "$base/setup-before.sha256"
sh setup.sh
sha256sum -c "$base/setup-before.sha256"
docker compose ps
docker compose exec -T prometheus promtool check metrics < "$base/metrics.txt"
