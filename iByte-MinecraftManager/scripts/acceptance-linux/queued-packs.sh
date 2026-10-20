#!/usr/bin/env bash
set -euo pipefail
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
cd /opt/imm-panel
php '/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager/scripts/acceptance-linux/queued-packs.php' > /opt/imm/logs/final-queued-packs.log 2>&1
tail -n 6 /opt/imm/logs/final-queued-packs.log
