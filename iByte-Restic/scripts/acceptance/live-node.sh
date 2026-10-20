#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture ]] || exit 1
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
EVIDENCE=hardening-live-node.txt
if [[ ${IBYTE_TEST_SERVER_DESTRUCTION_ONLY:-} == 1 ]]; then EVIDENCE=destruction-live-node.txt; fi
exec > >(tee "$ROOT/docs/evidence/$EVIDENCE") 2>&1
BASE=/opt/ibyte-acceptance/systemd-node
[[ -f $BASE/stock.sha256 ]] || { echo 'Run install-systemd.sh first.' >&2; exit 1; }
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$BASE/tls.key" -out "$BASE/tls.crt" -days 2 -subj /CN=localhost -addext subjectAltName=DNS:localhost >/dev/null 2>&1
chmod 0600 "$BASE/tls.key"
install -m 0644 "$BASE/tls.crt" /usr/local/share/ca-certificates/ibyte-acceptance.crt
update-ca-certificates >/dev/null
php "$ROOT/scripts/acceptance/configure-tls.php"
bash "$ROOT/scripts/acceptance/prepare-container.sh"
systemctl start ibyte-acceptance-panel
systemctl restart ibyte-acceptance-wings
bash "$ROOT/release/Wings Installer/install.sh" --allow-preview --node-id 1 --service ibyte-acceptance-wings --binary "$BASE/wings" --state "$BASE/installer" --repository-root /var/lib/ibyte-restic/repositories
php "$ROOT/scripts/acceptance/live-node.php"
systemctl stop ibyte-acceptance-wings ibyte-acceptance-panel
