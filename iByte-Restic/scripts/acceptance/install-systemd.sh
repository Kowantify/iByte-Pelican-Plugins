#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture && $(id -u) == 0 ]] || exit 1
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
exec > >(tee "$ROOT/docs/evidence/hardening-systemd.txt") 2>&1
BASE=/opt/ibyte-acceptance/systemd-node
if [[ ${1:-} != --resume ]]; then
[[ ! -e $BASE ]] || { echo 'Fixture already exists; inspect it before another run, then use --resume.' >&2; exit 1; }
mkdir -p "$BASE" /etc/pelican /var/lib/ibyte-acceptance/volumes /var/lib/ibyte-acceptance/backups
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 180 https://github.com/pelican/wings/releases/download/v1.0.0-rc.1/wings_linux_amd64 -o "$BASE/wings"
echo '19dda386e02041da1f1fbcc73f34e4bb01c1fbc2d499aac79a6be3a51ba7f0e8  wings' > "$BASE/stock.sha256"
(cd "$BASE" && sha256sum -c stock.sha256)
chmod 0755 "$BASE/wings"
install -m 0600 "$ROOT/scripts/acceptance/panel-mock.php" "$BASE/panel-mock.php"
cat > /etc/pelican/config.yml <<'YAML'
uuid: 11111111-1111-4111-8111-111111111111
token_id: acceptance
token: disposable-acceptance-token
api:
  host: 127.0.0.1
  port: 18080
  ssl:
    enabled: false
system:
  root_directory: /var/lib/ibyte-acceptance
  data: /var/lib/ibyte-acceptance/volumes
  backup_directory: /var/lib/ibyte-acceptance/backups
  archive_directory: /var/lib/ibyte-acceptance/archives
  log_directory: /var/log/ibyte-acceptance
  sftp:
    bind_address: 127.0.0.1
    bind_port: 12022
docker:
  network:
    name: host
remote: http://127.0.0.1:18081
YAML
chmod 0600 /etc/pelican/config.yml
cat > /etc/systemd/system/ibyte-acceptance-panel.service <<EOF
[Unit]
Description=iByte disposable empty Panel API fixture
[Service]
ExecStart=/usr/bin/php -S 127.0.0.1:18081 $BASE/panel-mock.php
Restart=no
EOF
cat > /etc/systemd/system/ibyte-acceptance-wings.service <<EOF
[Unit]
Description=iByte disposable real Wings acceptance
After=docker.service ibyte-acceptance-panel.service
[Service]
ExecStart=$BASE/wings --config /etc/pelican/config.yml
Restart=no
EOF
systemctl daemon-reload
systemctl start ibyte-acceptance-panel
systemctl start ibyte-acceptance-wings
sleep 3
systemctl is-active --quiet ibyte-acceptance-wings
echo 'PASS: official stock Wings starts with real Docker and systemd (empty mocked Panel inventory)'
printf 'preserve-game-data\n' > /var/lib/ibyte-acceptance/volumes/sentinel
fi
[[ -f $BASE/stock.sha256 && -f $BASE/panel-mock.php ]] || exit 1
systemctl start ibyte-acceptance-panel
systemctl restart ibyte-acceptance-wings
sleep 2
systemctl is-active --quiet ibyte-acceptance-wings
COMMON=(--allow-preview --node-id 1 --service ibyte-acceptance-wings --binary "$BASE/wings" --state "$BASE/installer" --repository-root /var/lib/ibyte-restic/repositories)
bash "$ROOT/.build/previous-releases/3.0.0-preview.1/Wings Installer/install.sh" "${COMMON[@]}"
sha256sum "$BASE/wings" > "$BASE/previous.sha256"
echo 'PASS: preview.1 installs through the bundled operator script on real systemd'
mkdir -p /etc/systemd/system/ibyte-acceptance-wings.service.d
cat > /etc/systemd/system/ibyte-acceptance-wings.service.d/failure.conf <<EOF
[Service]
ExecStartPre=/bin/sh -c '! $BASE/wings version | grep -q preview.2'
EOF
systemctl daemon-reload
if bash "$ROOT/release/Wings Installer/install.sh" "${COMMON[@]}"; then echo 'Injected failed startup unexpectedly succeeded'; exit 1; fi
sha256sum -c "$BASE/previous.sha256"
systemctl is-active --quiet ibyte-acceptance-wings
echo 'PASS: failed preview.2 upgrade restores and restarts previous real Wings'
rm -- /etc/systemd/system/ibyte-acceptance-wings.service.d/failure.conf
systemctl daemon-reload
bash "$ROOT/release/Wings Installer/install.sh" "${COMMON[@]}"
bash "$ROOT/release/Wings Installer/install.sh" "${COMMON[@]}"
echo 'PASS: preview.1 -> preview.2 upgrade and repeat installation pass real authenticated health checks'
[[ $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/api/restic/readiness) == 401 ]]
[[ $(cat /var/lib/ibyte-acceptance/volumes/sentinel) == preserve-game-data ]]
bash "$ROOT/release/Wings Installer/install.sh" "${COMMON[@]}" --rollback
(cd "$BASE" && sha256sum -c stock.sha256)
systemctl is-active --quiet ibyte-acceptance-wings
echo 'PASS: unauthenticated addon access refused; explicit rollback restores official stock Wings and preserves game data'
systemctl stop ibyte-acceptance-wings ibyte-acceptance-panel
