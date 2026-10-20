#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
test -d /opt/imm-panel/vendor/laravel/framework
test ! -f /opt/imm/fixture.json
mkdir -p /opt/imm-panel/storage/framework/{cache/data,views,sessions} /opt/imm-panel/storage/logs /opt/imm-panel/storage/app/public
# Copy only after Windows tests stop writing their database. Discard the earlier
# rsync snapshot of temporary test work; it is never used for acceptance.
cp "$workspace/.research/panel/database/imm-preview.sqlite" /opt/imm-panel/database/imm-acceptance.sqlite
rsync -a "$workspace/.research/panel/plugins/ibyte-minecraft-manager/" /opt/imm-panel/plugins/ibyte-minecraft-manager/
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
php "$workspace/scripts/acceptance-linux/import.php"
bash "$workspace/scripts/acceptance-linux/start.sh"
