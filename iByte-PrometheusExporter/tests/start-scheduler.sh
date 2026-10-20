#!/bin/bash
# Start the Laravel scheduler worker so the five-second plugin collector runs continuously.
# Run inside the acceptance WSL distribution only.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
base=/opt/ibyte-prometheus
cd "$base/panel" || exit 1
if ! pgrep -f "schedule:work" >/dev/null; then
  setsid php artisan schedule:work </dev/null >"$base/schedule.log" 2>&1 &
fi
sleep 3
if pgrep -f "schedule:work" >/dev/null; then
  echo 'Scheduler worker running.'
else
  echo 'Scheduler worker failed:' >&2
  tail -n 5 "$base/schedule.log" >&2
  exit 1
fi