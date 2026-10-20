#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database IMM_ACCEPTANCE_EVIDENCE=live-performance18
cd /opt/imm-panel
# The measured clean NeoForge install has already completed with 50 ms/request.
php "$workspace/scripts/acceptance-linux/packages.php" neoforge1211fast > /opt/imm/logs/neoforge1211fast-packages.log 2>&1
php "$workspace/scripts/acceptance-linux/boot.php" neoforge1211fast > /opt/imm/logs/neoforge1211fast-modded-boot.log 2>&1
cp /opt/imm/neoforge1211fast-startup.json /opt/imm/neoforge1211fast-packages-startup.json
echo 'PASS NeoForge 1.21.1 two-mod batch and game boot'
for name in fabric1211 forge1201 paper1214; do
    bash "$workspace/scripts/acceptance-linux/run-case.sh" "$name" boot > "/opt/imm/logs/$name-case.log" 2>&1
    php "$workspace/scripts/acceptance-linux/packages.php" "$name" > "/opt/imm/logs/$name-packages.log" 2>&1
    php "$workspace/scripts/acceptance-linux/boot.php" "$name" > "/opt/imm/logs/$name-modded-boot.log" 2>&1
    cp "/opt/imm/$name-startup.json" "/opt/imm/$name-packages-startup.json"
    echo "PASS $name runtime, two-package batch and game boot"
    php "$workspace/scripts/acceptance-linux/export.php"
done
php "$workspace/scripts/acceptance-linux/rollback.php" > /opt/imm/logs/live-rollback.log 2>&1
echo 'PASS real bulk-rename missing-source recovery'
php "$workspace/scripts/acceptance-linux/export.php"
