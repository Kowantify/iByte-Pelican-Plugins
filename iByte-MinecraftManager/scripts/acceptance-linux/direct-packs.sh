#!/usr/bin/env bash
set -uo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
failures=0
for case_name in cobblemon nexus odyssey; do
    echo "Testing direct pack installation and startup: $case_name"
    if bash "$workspace/scripts/acceptance-linux/run-case.sh" "$case_name" boot > "/opt/imm/logs/$case_name-case.log" 2>&1; then
        echo "PASSED $case_name"
    else
        failures=$((failures+1)); echo "FAILED $case_name"; tail -n 12 "/opt/imm/logs/$case_name-case.log"
    fi
done
echo "Direct pack failures: $failures"
test "$failures" -eq 0
