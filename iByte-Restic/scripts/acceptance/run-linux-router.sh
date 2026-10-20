#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture ]] || exit 1
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
export PATH="/opt/ibyte-acceptance/go/bin:$PATH"
export GOCACHE=/opt/ibyte-acceptance/gocache GOMODCACHE=/opt/ibyte-acceptance/gomodcache TMPDIR=/opt/ibyte-acceptance/tmp
export RESTIC_TEST_BINARY=/opt/ibyte-acceptance/bin/restic
cp "$ROOT/wings-addon/ibyte/restic/routes_linux_test.go" "$ROOT/.build/wings-src/ibyte/restic/"
cp "$ROOT/wings-addon/cmd/node-tool/"*.go "$ROOT/.build/wings-src/ibyte/cmd/node-tool/"
cd "$ROOT/.build/wings-src"
go test -race -count=1 -v ./ibyte/restic ./ibyte/cmd/node-tool > "$ROOT/docs/evidence/hardening-linux-router-helper.txt" 2>&1
cd "$ROOT"
php scripts/package-panel-plugin.php
php scripts/test-panel.php > docs/evidence/hardening-linux-panel.txt 2>&1
php scripts/test-panel-queue.php > docs/evidence/hardening-linux-queue.txt 2>&1
