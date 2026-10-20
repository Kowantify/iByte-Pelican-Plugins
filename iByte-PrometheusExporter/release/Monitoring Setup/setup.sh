#!/bin/sh
set -eu
cd "$(dirname "$0")"
umask 077

for command in docker openssl flock; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command missing: $command" >&2; exit 1; }
done
exec 9>.setup.lock
flock -n 9 || { echo 'Another setup is running.' >&2; exit 1; }
trap 'rm -f prometheus.yml.tmp; if [ "${echo_disabled:-0}" = 1 ]; then stty echo; fi' EXIT HUP INT TERM

if ! command -v docker >/dev/null 2>&1; then
  echo 'Docker with Compose is required for the optional fresh stack.' >&2
  exit 1
fi

if [ ! -f prometheus.yml ]; then
  : "${PANEL_TARGET:?Set PANEL_TARGET to the Panel HTTPS host and port, e.g. panel.example.com:443}"
  case "$PANEL_TARGET" in
    *[!a-zA-Z0-9.:-]*|'') echo 'PANEL_TARGET must be a DNS name or IP and port.' >&2; exit 1 ;;
  esac
  mkdir -p secrets
  if [ ! -f secrets/pelican_scrape_token ]; then
    if [ -z "${PANEL_TOKEN:-}" ]; then
      [ -t 0 ] || { echo 'Run interactively or set PANEL_TOKEN.' >&2; exit 1; }
      printf 'Paste the one-time Panel scrape token: ' >&2
      stty -echo
      echo_disabled=1
      IFS= read -r PANEL_TOKEN
      stty echo
      echo_disabled=0
      printf '\n' >&2
    fi
    case "$PANEL_TOKEN" in *[!a-zA-Z0-9]*|'') echo 'Invalid scrape token.' >&2; exit 1 ;; esac
    [ "${#PANEL_TOKEN}" = 64 ] || { echo 'Expected a 64-character plugin token.' >&2; exit 1; }
    printf '%s' "$PANEL_TOKEN" > secrets/pelican_scrape_token
  fi
  if [ ! -f secrets/grafana_admin_password ]; then
    openssl rand -hex 24 > secrets/grafana_admin_password
  fi
  cat > prometheus.yml.tmp <<EOF
global:
  scrape_interval: 5s
  evaluation_interval: 5s
scrape_configs:
  - job_name: pelican_ibyte
    scheme: https
    metrics_path: /ibyte-prometheus-exporter/metrics
    scrape_interval: 5s
    scrape_timeout: 4s
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/pelican_scrape_token
    static_configs:
      - targets: ['$PANEL_TARGET']
EOF
  mv prometheus.yml.tmp prometheus.yml
fi

# This directory is private on the host. Bind-mounted files need read access
# for the non-root service users inside their containers.
chmod 700 secrets
chmod 644 secrets/pelican_scrape_token secrets/grafana_admin_password
chmod 644 prometheus.yml

if [ ! -f .env ]; then
  PROMETHEUS_RETENTION=${PROMETHEUS_RETENTION:-30d}
  case "$PROMETHEUS_RETENTION" in
    *[!0-9dwyhms]*|'') echo 'Invalid PROMETHEUS_RETENTION.' >&2; exit 1 ;;
  esac
  printf 'PROMETHEUS_RETENTION=%s\nPROMETHEUS_RETENTION_SIZE=5GB\n' "$PROMETHEUS_RETENTION" > .env
fi
mkdir -p provisioning/datasources provisioning/dashboards dashboards
if [ ! -f provisioning/datasources/prometheus.yml ]; then
  cat > provisioning/datasources/prometheus.yml <<'EOF'
apiVersion: 1
datasources:
  - name: Pelican Prometheus
    uid: ibyte-prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
    jsonData:
      timeInterval: 5s
EOF
fi
if [ ! -f provisioning/dashboards/pelican.yml ]; then
  cat > provisioning/dashboards/pelican.yml <<'EOF'
apiVersion: 1
providers:
  - name: Pelican
    type: file
    disableDeletion: true
    options:
      path: /var/lib/grafana/dashboards
EOF
fi
if [ ! -f dashboards/pelican.json ]; then
  sed 's/${DS_PROMETHEUS}/ibyte-prometheus/g' dashboard.json > dashboards/pelican.json
fi
chmod 755 provisioning provisioning/datasources provisioning/dashboards dashboards
chmod 644 provisioning/datasources/prometheus.yml provisioning/dashboards/pelican.yml dashboards/pelican.json
docker compose config -q
docker compose run --rm --no-deps --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml
docker compose up -d
echo 'Prometheus: http://127.0.0.1:9090  Grafana: http://127.0.0.1:3000'
echo 'Grafana admin password is in secrets/grafana_admin_password (keep private).'
