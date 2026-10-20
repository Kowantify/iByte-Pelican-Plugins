#!/usr/bin/env bash
set -euo pipefail
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
cd /opt/imm-panel
php "$workspace/scripts/acceptance-linux/import.php"
php "$workspace/scripts/acceptance-linux/install.php" "$1" > "/opt/imm/logs/$1-installation.log" 2>&1
cat "/opt/imm/logs/$1-installation.log"
if test "${2:-}" = boot; then php "$workspace/scripts/acceptance-linux/boot.php" "$1"; fi
