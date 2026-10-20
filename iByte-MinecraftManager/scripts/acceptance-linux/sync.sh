#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
rsync -a "$workspace/plugin/src/" /opt/imm-panel/plugins/ibyte-minecraft-manager/src/
rsync -a "$workspace/plugin/lang/" /opt/imm-panel/plugins/ibyte-minecraft-manager/lang/
rsync -a "$workspace/plugin/resources/" /opt/imm-panel/plugins/ibyte-minecraft-manager/resources/
echo 'Updated isolated plugin implementation; retained native installation metadata.'
