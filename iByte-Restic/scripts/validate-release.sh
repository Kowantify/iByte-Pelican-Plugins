#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
cd -- "$ROOT/.build/release-artifacts"
sha256sum --check SHA256SUMS
php "$ROOT/scripts/validate-release.php"
bash "$ROOT/release/Wings Installer/install.sh" --artifacts "$ROOT/.build/release-artifacts" --verify-only
echo 'Two-folder release, readable installer, audit files and checksums validated; production gates are separate.'
