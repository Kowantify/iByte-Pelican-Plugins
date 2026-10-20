#!/usr/bin/env bash
set -euo pipefail
export DB_DATABASE=imm-acceptance.sqlite APP_URL=http://127.0.0.1:18096 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
cd /opt/imm-panel
# Only run after the queued-packs invocation has completed and released its worker.
php "$workspace/scripts/acceptance-linux/final-state.php"
php "$workspace/scripts/acceptance-linux/rollback.php"
php "$workspace/scripts/acceptance-linux/scheduler.php"
php "$workspace/scripts/acceptance-linux/filesystem.php"
php "$workspace/scripts/acceptance-linux/runtime-info.php"
php "$workspace/scripts/acceptance-linux/export.php"
php "$workspace/scripts/acceptance-linux/final-state.php"
php "$workspace/scripts/acceptance-linux/exit-states.php"
php "$workspace/scripts/acceptance-linux/verify-candidate.php"
