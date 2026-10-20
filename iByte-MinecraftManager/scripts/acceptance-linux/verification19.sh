#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database IMM_ACCEPTANCE_EVIDENCE=live-verification19
cd /opt/imm-panel
bash "$workspace/scripts/acceptance-linux/run-case.sh" neoforge1211 boot > /opt/imm/logs/neoforge1211-case.log 2>&1
echo 'PASS latest NeoForge 1.21.1 installation and boot'
php -r '$p="/opt/imm/fixture.json";$f=json_decode(file_get_contents($p),true);$f["cases"]["neoforge1211fast"]=$f["cases"]["neoforge1211"];file_put_contents($p,json_encode($f));'
php "$workspace/scripts/acceptance-linux/packages.php" neoforge1211fast > /opt/imm/logs/neoforge1211-packages.log 2>&1
php "$workspace/scripts/acceptance-linux/boot.php" neoforge1211 > /opt/imm/logs/neoforge1211-modded-boot.log 2>&1
cp /opt/imm/neoforge1211-startup.json /opt/imm/neoforge1211-packages-startup.json
echo 'PASS two real mods, verification and modded game startup'
php "$workspace/scripts/acceptance-linux/rollback.php" neoforge1211 > /opt/imm/logs/live-rollback.log 2>&1
echo 'PASS asynchronous verification detects missing destination and restores original mods'
php artisan minecraft-manager:work --stop-when-empty > /opt/imm/logs/final-worker-drain.log 2>&1
php "$workspace/scripts/acceptance-linux/verify-candidate.php"
php "$workspace/scripts/acceptance-linux/verification19-state.php"
php "$workspace/scripts/acceptance-linux/export.php"
