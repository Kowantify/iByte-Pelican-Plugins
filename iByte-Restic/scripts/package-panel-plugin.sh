#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
php scripts/package-panel-plugin.php
