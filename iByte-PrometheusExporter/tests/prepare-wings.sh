#!/bin/bash
set -euo pipefail
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || exit 1
project=$(cd "$(dirname "$0")/.." && pwd)
base=/opt/ibyte-prometheus
address=$(hostname -I | awk '{print $1}')
printf '%s node1.fixture.test node2.fixture.test panel.fixture.test\n' "$address" >> /etc/hosts
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=panel.fixture.test \
  -addext subjectAltName=DNS:panel.fixture.test,DNS:node1.fixture.test,DNS:node2.fixture.test \
  -keyout "$base/tls.key" -out "$base/tls.crt" >/dev/null 2>&1
chmod 600 "$base/tls.key"
cp "$base/tls.crt" /usr/local/share/ca-certificates/ibyte-prometheus-fixture.crt
update-ca-certificates >/dev/null
install -m 0755 "$project/.tools/wings" "$base/wings"
php "$project/tests/panel.php" seed-nodes
"$base/wings" version
