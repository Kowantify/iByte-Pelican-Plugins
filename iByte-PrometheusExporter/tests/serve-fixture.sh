#!/bin/bash
# Serve the disposable test Panel and two Wings daemons, detached from the calling shell.
# Run inside the acceptance WSL distribution only.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
base=/opt/ibyte-prometheus

if ! ss -tln | grep -q ':8090 '; then
  cd "$base/panel" || exit 1
  setsid php artisan serve --host=127.0.0.1 --port=8090 </dev/null >"$base/panel.log" 2>&1 &
fi
if ! ss -tln | grep -q ':18441 '; then
  cd "$base/node1" || exit 1
  setsid "$base/wings" --config "$base/node1/config.yml" </dev/null >"$base/node1.log" 2>&1 &
fi
if ! ss -tln | grep -q ':18442 '; then
  cd "$base/node2" || exit 1
  setsid "$base/wings" --config "$base/node2/config.yml" </dev/null >"$base/node2.log" 2>&1 &
fi

for attempt in $(seq 1 30); do
  if ss -tln | grep -q ':8090 ' && ss -tln | grep -q ':18441 ' && ss -tln | grep -q ':18442 '; then
    echo 'Panel, node1 and node2 are listening.'
    exit 0
  fi
  sleep 1
done
echo 'Ports did not open:' >&2
ss -tln | grep -e 8090 -e 18441 -e 18442 >&2 || true
tail -n 5 "$base/panel.log" "$base/node1.log" "$base/node2.log" >&2 || true
exit 1