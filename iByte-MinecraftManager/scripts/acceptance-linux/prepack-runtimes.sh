#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
php "$workspace/scripts/acceptance-linux/import.php"
php "$workspace/scripts/acceptance-linux/install.php" nexus NEOFORGE 1.21.1 > /opt/imm/logs/nexus-before-pack.log 2>&1
cp /opt/imm/nexus-installation.json /opt/imm/nexus-before-pack-installation.json
php "$workspace/scripts/acceptance-linux/install.php" cobblemon VANILLA 1.20.1 > /opt/imm/logs/cobblemon-before-pack.log 2>&1
cp /opt/imm/cobblemon-installation.json /opt/imm/cobblemon-before-pack-installation.json
echo 'Installed two actual different runtimes before their pack switches; no worlds were started or downgraded.'
