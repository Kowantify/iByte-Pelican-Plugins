#!/usr/bin/env bash
set -euo pipefail
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
php "$workspace/scripts/acceptance-linux/packages.php" "$1"
php "$workspace/scripts/acceptance-linux/boot.php" "$1"
cp "/opt/imm/$1-startup.json" "/opt/imm/$1-packages-startup.json"
