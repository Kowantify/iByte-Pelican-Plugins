#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database IMM_ACCEPTANCE_EVIDENCE=live-catalog17
cd /opt/imm-panel
for name in neoforge263 fabric1211 forge1201; do
    echo "Installing runtime: $name"
    bash "$workspace/scripts/acceptance-linux/run-case.sh" "$name" boot > "/opt/imm/logs/$name-case.log" 2>&1
    echo "PASS runtime installation and fresh startup: $name"
    php "$workspace/scripts/acceptance-linux/packages.php" "$name" > "/opt/imm/logs/$name-packages.log" 2>&1
    echo "PASS two-mod batch: $name"
    php "$workspace/scripts/acceptance-linux/boot.php" "$name" > "/opt/imm/logs/$name-packages-boot.log" 2>&1
    cp "/opt/imm/$name-startup.json" "/opt/imm/$name-packages-startup.json"
    echo "PASS modded startup and clean shutdown: $name"
done
php "$workspace/scripts/acceptance-linux/export.php"
