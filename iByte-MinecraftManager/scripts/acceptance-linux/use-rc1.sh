#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
pid="$(cat /opt/imm/panel.pid)"
if test -r "/proc/$pid/cmdline" && tr '\0' ' ' < "/proc/$pid/cmdline" | grep -Fq 'php -S 127.0.0.1:18096'; then kill "$pid"; fi
tar -xzf "$workspace/.build/panel-rc1.tar.gz" --exclude=composer.json --exclude=composer.lock -C /opt/imm-panel
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
cd /opt/imm-panel
nohup php -S 127.0.0.1:18096 -t public public/index.php > /opt/imm/logs/panel-http.log 2>&1 < /dev/null & echo $! > /opt/imm/panel.pid
echo 'Official published Panel RC.1 core installed in isolated fixture; audited dependency set retained.'
