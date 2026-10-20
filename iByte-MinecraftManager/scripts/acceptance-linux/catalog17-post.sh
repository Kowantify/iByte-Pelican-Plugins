#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database IMM_ACCEPTANCE_EVIDENCE=live-catalog17
cd /opt/imm-panel
cp -a "$workspace/.research/panel/public/build" public/
php "$workspace/scripts/acceptance-linux/catalog17-queue.php" > /opt/imm/logs/live-ui-mod-queue.log 2>&1
echo 'PASS live UI mod queue delivered by actual worker'
php "$workspace/scripts/acceptance-linux/boot.php" neoforge263 > /opt/imm/logs/neoforge263-queued-mods-boot.log 2>&1
cp /opt/imm/neoforge263-startup.json "$workspace/docs/evidence/live-catalog17/neoforge263-queued-mods-startup.json"
echo 'PASS game startup after queued UI installation'
php "$workspace/scripts/acceptance-linux/rollback.php" > /opt/imm/logs/live-rollback.log 2>&1
echo 'PASS actual Wings missing-source rollback preserves mods and world'
php artisan minecraft-manager:work --stop-when-empty > /opt/imm/logs/final-worker-drain.log 2>&1
php "$workspace/scripts/acceptance-linux/verify-candidate.php"
php "$workspace/scripts/acceptance-linux/catalog17-state.php"
php "$workspace/scripts/acceptance-linux/export.php"
