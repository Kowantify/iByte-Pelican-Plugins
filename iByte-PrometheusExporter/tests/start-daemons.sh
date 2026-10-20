#!/bin/bash
# Launch background daemons for the disposable acceptance fixture (dockerd, panel TLS proxy,
# two Wings nodes) when they are not already running. Run inside the acceptance WSL distribution.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
base=/opt/ibyte-prometheus
project=/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican\ Extensions/iByte-PrometheusExporter

if ! docker info >/dev/null 2>&1; then
  nohup dockerd >"$base/docker.log" 2>&1 < /dev/null &
  for _ in $(seq 1 20); do docker info >/dev/null 2>&1 && break; sleep 1; done
fi
docker info >/dev/null 2>&1 || { echo 'dockerd failed to start' >&2; exit 1; }

if ! pgrep -f tls-proxy.py >/dev/null; then
  nohup python3 "$project/tests/tls-proxy.py" >"$base/tls-proxy.log" 2>&1 < /dev/null &
fi

if ! pgrep -f "wings --config $base/node1/config.yml" >/dev/null; then
  (cd "$base/node1" && nohup "$base/wings" --config "$base/node1/config.yml" >"$base/node1.log" 2>&1 < /dev/null &)
fi
if ! pgrep -f "wings --config $base/node2/config.yml" >/dev/null; then
  (cd "$base/node2" && nohup "$base/wings" --config "$base/node2/config.yml" >"$base/node2.log" 2>&1 < /dev/null &)
fi

sleep 2
pgrep -af 'wings --config|tls-proxy.py' || true
docker info 2>&1 | grep 'Server Version' || true