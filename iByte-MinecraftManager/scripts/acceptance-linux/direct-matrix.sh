#!/usr/bin/env bash
set -uo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
failures=0
for case_name in vanilla1201 paper1214 forge1201 neoforge1211 quilt1201 vanilla263; do
    echo "Testing direct download and startup: $case_name"
    if bash "$workspace/scripts/acceptance-linux/run-case.sh" "$case_name" boot > "/opt/imm/logs/$case_name-case.log" 2>&1; then
        echo "PASSED $case_name"
    else
        failures=$((failures+1)); echo "FAILED $case_name"; tail -n 8 "/opt/imm/logs/$case_name-case.log"
    fi
done
for case_name in fabric1211 forge1201 paper1214 neoforge1211; do
    echo "Testing direct mod/plugin download and startup: $case_name"
    if bash "$workspace/scripts/acceptance-linux/packages.sh" "$case_name" > "/opt/imm/logs/$case_name-packages.log" 2>&1; then
        echo "PASSED packages $case_name"
    else
        failures=$((failures+1)); echo "FAILED packages $case_name"; tail -n 8 "/opt/imm/logs/$case_name-packages.log"
    fi
done
echo "Direct matrix failures: $failures"
test "$failures" -eq 0
