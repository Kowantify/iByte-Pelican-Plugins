#!/usr/bin/env bash
set -euo pipefail
cd /opt/imm-panel
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
if ! test -f database/imm-acceptance.sqlite; then cp database/imm-preview.sqlite database/imm-acceptance.sqlite; fi
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
php "$workspace/scripts/acceptance-linux/fixture.php"
test -f /opt/imm/fixture.json
test -f /opt/imm/wings.yml
if ! test -f /opt/imm/panel.pid || ! kill -0 "$(cat /opt/imm/panel.pid)" 2>/dev/null; then
    nohup php -S 127.0.0.1:18096 -t public public/index.php > /opt/imm/logs/panel-http.log 2>&1 < /dev/null & echo $! > /opt/imm/panel.pid
fi
if ! test -f /opt/imm/wings.pid || ! kill -0 "$(cat /opt/imm/wings.pid)" 2>/dev/null; then
    DOCKER_HOST=unix:///run/imm-docker.sock nohup /opt/imm/bin/wings --config /opt/imm/wings.yml > /opt/imm/logs/wings.log 2>&1 < /dev/null & echo $! > /opt/imm/wings.pid
fi
sleep 3
tail -n 12 /opt/imm/logs/wings.log
