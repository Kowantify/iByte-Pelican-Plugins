#!/usr/bin/env bash
set -euo pipefail
exec bash "$(cd -- "$(dirname -- "$0")" && pwd)/install-wings-restic.sh" --rollback "$@"
