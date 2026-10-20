#!/usr/bin/env bash
set -euo pipefail
test "$(cat /etc/debian_version | cut -d. -f1)" = 13
mkdir -p /opt/imm/logs /opt/imm/bin
if ! command -v php >/dev/null || ! test -x /usr/sbin/dockerd; then
    apt-get update > /opt/imm/logs/apt-update.log 2>&1
    DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl unzip rsync docker.io docker-cli php8.4-cli php8.4-curl php8.4-mbstring php8.4-sqlite3 php8.4-zip php8.4-intl php8.4-gd php8.4-xml php8.4-bcmath php8.4-redis > /opt/imm/logs/apt-install.log 2>&1
fi
if ! test -x /usr/bin/docker; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y docker-cli > /opt/imm/logs/docker-cli-install.log 2>&1
fi
if ! test -S /run/imm-docker.sock; then
    nohup /usr/sbin/dockerd --host=unix:///run/imm-docker.sock --data-root=/var/lib/imm-docker --exec-root=/run/imm-docker --pidfile=/run/imm-docker.pid --bridge=none --iptables=false --ip6tables=false --ip-forward=false --ip-masq=false > /opt/imm/logs/docker.log 2>&1 < /dev/null &
fi
export DOCKER_HOST=unix:///run/imm-docker.sock
for attempt in $(seq 1 30); do
    if /usr/bin/docker info > /opt/imm/logs/docker-info.txt 2>&1; then break; fi
    sleep 1
done
/usr/bin/docker version --format '{{.Server.Version}}'
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
install -m 755 "$workspace/.build/wings_linux_amd64" /opt/imm/bin/wings
/opt/imm/bin/wings version
if test "${1:-}" = --runtime-only; then echo 'Isolated tools and private Docker ready; Panel copy deferred.'; exit 0; fi
mkdir -p /opt/imm-panel
rsync -a --exclude=node_modules --exclude=storage/logs --exclude=storage/framework/cache --exclude=storage/framework/views --exclude=storage/framework/sessions --exclude=public/build "$workspace/.research/panel/" /opt/imm-panel/
mkdir -p /opt/imm-panel/storage/framework/{cache,views,sessions} /opt/imm-panel/storage/logs
rsync -a "$workspace/plugin/" /opt/imm-panel/plugins/ibyte-minecraft-manager/
echo 'Isolated runtime and native Panel copied.'
