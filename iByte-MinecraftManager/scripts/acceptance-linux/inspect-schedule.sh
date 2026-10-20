#!/usr/bin/env bash
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
cd /opt/imm-panel
php '/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager/scripts/acceptance-linux/inspect-schedule.php'
tail -n 8 storage/logs/minecraft-manager-worker.log
