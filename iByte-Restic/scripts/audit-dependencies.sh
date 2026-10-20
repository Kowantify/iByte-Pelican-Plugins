#!/usr/bin/env bash
# Run against the actual candidate patch set; build scripts restore clean upstream trees on exit.
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
WINGS="$ROOT/.build/wings-release"
RESTIC="$ROOT/.build/restic-runtime"
mkdir -p "$ROOT/docs/evidence"
for src in "$WINGS" "$RESTIC"; do
 [[ -d $src/.git && -z $(git -C "$src" status --porcelain --untracked-files=no) ]] || { echo 'Expected clean prepared upstream checkout' >&2; exit 1; }
done
WP="$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch"
WD="$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1-security-deps.patch"
RP="$ROOT/restic-runtime/patches/v0.19.1-security-deps.patch"
git -C "$WINGS" apply --check "$WP" "$WD"
git -C "$RESTIC" apply --check "$RP"
git -C "$WINGS" apply "$WP" "$WD"
git -C "$RESTIC" apply "$RP"
trap 'git -C "$RESTIC" apply --reverse "$RP"; git -C "$WINGS" apply --reverse "$WD" "$WP"' EXIT
failed=0
run(){ local name=$1; shift; local code=0; "$@" > "$ROOT/docs/evidence/ci-$name.txt" 2>&1 || code=$?; printf '%s exit: %s\n' "$name" "$code"; if [[ $code != 0 ]]; then failed=1; fi; }
cd "$RESTIC"
run restic-source govulncheck -tags=disable_grpc_modules ./cmd/restic
cd "$WINGS"
run wings-source govulncheck ./...
for arch in amd64 arm64; do
 run "restic-$arch" govulncheck -mode=binary "$ROOT/.build/release-artifacts/restic-0.19.1-linux-$arch"
 run "helper-$arch" govulncheck -mode=binary "$ROOT/.build/release-artifacts/ibyte-node-tool-v3.0.0-preview.2-linux-$arch"
 run "wings-$arch" govulncheck -mode=binary "$ROOT/.build/release-artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-$arch"
done
[[ $failed == 0 ]] || { echo 'Dependency security gate failed; all reports retained, no suppressions.' >&2; exit 1; }
