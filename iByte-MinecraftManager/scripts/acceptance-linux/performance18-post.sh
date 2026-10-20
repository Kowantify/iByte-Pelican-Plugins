#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database IMM_ACCEPTANCE_EVIDENCE=live-performance18
cd /opt/imm-panel
php "$workspace/scripts/acceptance-linux/import.php"
php -r '$p="/opt/imm/fixture.json";$f=json_decode(file_get_contents($p),true);$c=$f["cases"]["vanilla263"];if(is_file("/opt/imm/volumes/".$c["uuid"]."/server.jar")){throw new RuntimeException("Clean volume required");}$f["cases"]["neoforge1211direct"]=[...$c,"provider"=>"mcjars","project"=>"NEOFORGE","release"=>"1.21.1"];file_put_contents($p,json_encode($f));'
php "$workspace/scripts/acceptance-linux/install.php" neoforge1211direct > /opt/imm/logs/neoforge1211direct-installation.log 2>&1
echo 'PASS clean NeoForge 1.21.1 install without artificial latency'
php "$workspace/scripts/acceptance-linux/install.php" cobblemon > /opt/imm/logs/cobblemon-installation.log 2>&1
echo 'PASS actual Modrinth pack and automatic runtime install'
php "$workspace/scripts/acceptance-linux/boot.php" cobblemon > /opt/imm/logs/cobblemon-boot.log 2>&1
echo 'PASS modpack game startup'
php artisan minecraft-manager:work --stop-when-empty > /opt/imm/logs/final-worker-drain.log 2>&1
php "$workspace/scripts/acceptance-linux/verify-candidate.php"
php "$workspace/scripts/acceptance-linux/performance18-state.php"
php "$workspace/scripts/acceptance-linux/export.php"
