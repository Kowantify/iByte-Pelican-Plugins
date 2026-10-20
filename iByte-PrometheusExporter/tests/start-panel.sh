#!/bin/bash
# Serve the disposable test Panel on 127.0.0.1:8090 for the Wings remote API.
# Run inside the acceptance WSL distribution only.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
base=/opt/ibyte-prometheus
if ! pgrep -f "artisan serve" >/dev/null; then
  cd "$base/panel" || exit 1
  nohup php artisan serve --host=127.0.0.1 --port=8090 >"$base/panel.log" 2>&1 < /dev/null &
fi
sleep 3
ss -tln | grep 8090 || { echo 'Panel did not start'; tail -n 5 "$base/panel.log"; exit 1; }
echo 'Panel remote API listening on 127.0.0.1:8090'