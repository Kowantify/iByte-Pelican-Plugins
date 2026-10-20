#!/usr/bin/env bash
# Compatibility entry point: build the pinned-source runtime centrally;
# the old official precompiled v0.19.1 binary has known scan findings.
set -euo pipefail
exec bash "$(cd -- "$(dirname -- "$0")" && pwd)/build-restic-runtime.sh"
