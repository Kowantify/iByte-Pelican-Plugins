#!/usr/bin/env bash
set -euo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
test ! -f /opt/imm/fixture.json
mkdir -p /opt/imm-panel
tar -xf "$workspace/.build/panel-acceptance17.tar" -C /opt/imm-panel
export IMM_ACCEPTANCE_EVIDENCE=live-verification19
bash "$workspace/scripts/acceptance-linux/finish-setup.sh"
