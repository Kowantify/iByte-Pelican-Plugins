#!/usr/bin/env bash
set -euo pipefail
if test -n "$(DOCKER_HOST=unix:///run/imm-docker.sock /usr/bin/docker ps -q)"; then echo 'A test game is still running; refusing teardown.' >&2; exit 1; fi
stop_owned() {
    local file="$1" expected="$2" pid
    if test -f "$file"; then
        pid="$(cat "$file")"
        if test -r "/proc/$pid/cmdline" && tr '\0' ' ' < "/proc/$pid/cmdline" | grep -Fq -- "$expected"; then kill "$pid"; fi
    fi
}
stop_owned /opt/imm/wings.pid '/opt/imm/bin/wings --config /opt/imm/wings.yml'
stop_owned /opt/imm/panel.pid 'php -S 127.0.0.1:18096'
stop_owned /run/imm-docker.pid '--host=unix:///run/imm-docker.sock'
echo 'Stopped only this task’s private Wings, Panel and Docker services; all test games were already stopped.'
