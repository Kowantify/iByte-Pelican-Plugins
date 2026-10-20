#!/bin/bash
# Ensure the fixture hostnames resolve to this machine in /etc/hosts.
# Run inside the acceptance WSL distribution only.
set -u
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
address=$(hostname -I | awk '{print $1}')
for host in node1.fixture.test node2.fixture.test panel.fixture.test; do
  if ! grep -q " $host" /etc/hosts; then
    printf '%s %s\n' "$address" "$host" >> /etc/hosts
  fi
done
getent hosts node1.fixture.test node2.fixture.test panel.fixture.test