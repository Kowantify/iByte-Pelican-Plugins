#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
: "${RESTIC_TEST_BINARY:?Set an absolute verified Restic 0.19.1 binary; core integration tests must not silently skip.}"
php scripts/package-panel-plugin.php
php scripts/test-panel.php
bash scripts/test-installer.sh
git -C .upstream/wings apply --check ../../wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch
(cd wings-addon; go test -v ./ibyte/restic/engine)
