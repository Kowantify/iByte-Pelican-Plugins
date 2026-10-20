#!/usr/bin/env bash
set -uo pipefail
workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager'
failures=0
for case_name in fabric1211 forge1201 neoforge1211 quilt1201 vanilla263; do
    echo "Testing $case_name"
    bash "$workspace/scripts/acceptance-linux/run-case.sh" "$case_name" boot > "/opt/imm/logs/$case_name-case.log" 2>&1
    result=$?
    if test "$result" -ne 0; then failures=$((failures+1)); echo "FAILED $case_name"; tail -n 3 "/opt/imm/logs/$case_name-case.log"; else echo "PASSED $case_name"; fi
done
echo "Runtime matrix failures: $failures"
test "$failures" -eq 0
