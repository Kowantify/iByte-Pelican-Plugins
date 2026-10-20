#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture ]] || { echo 'Disposable Linux fixture required.' >&2; exit 1; }
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
export PATH="/opt/ibyte-acceptance/go/bin:$PATH"
export GOCACHE=/opt/ibyte-acceptance/gocache
export GOMODCACHE=/opt/ibyte-acceptance/gomodcache
mkdir -p /opt/ibyte-acceptance/bin /opt/ibyte-acceptance/tmp
chmod 0700 /opt/ibyte-acceptance/tmp
install -m 0700 "$ROOT/.build/release-artifacts/restic-0.19.1-linux-amd64" /opt/ibyte-acceptance/bin/restic
export RESTIC_TEST_BINARY=/opt/ibyte-acceptance/bin/restic
export TMPDIR=/opt/ibyte-acceptance/tmp
mkdir -p "$ROOT/docs/evidence"
printf 'Linux environment: '; uname -sr
php -v
go version
systemctl is-system-running || [[ $(systemctl is-system-running) == degraded ]]
cd "$ROOT/wings-addon"
go test -race -count=1 -v ./ibyte/restic/engine > "$ROOT/docs/evidence/hardening-linux-engine.txt" 2>&1
cd "$ROOT"
php scripts/package-panel-plugin.php
php scripts/test-panel.php > docs/evidence/hardening-linux-panel.txt 2>&1
php scripts/test-panel-queue.php > docs/evidence/hardening-linux-queue.txt 2>&1
