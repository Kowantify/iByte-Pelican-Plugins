#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
SHA=7b489da7b8196d0b4baef3e2db062c06d743e2f9
SRC="$ROOT/.build/wings-release"
if [[ ! -d $SRC/.git ]]; then git clone https://github.com/pelican/wings.git "$SRC"; fi
[[ $(git -C "$SRC" rev-parse HEAD) == "$SHA" ]] || git -C "$SRC" checkout --detach "$SHA"
[[ -z $(git -C "$SRC" status --porcelain --untracked-files=no) ]] || { echo 'Build source must be clean' >&2; exit 1; }
git -C "$SRC" apply --check "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch"
git -C "$SRC" apply "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch"
git -C "$SRC" apply --check "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1-security-deps.patch"
git -C "$SRC" apply "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1-security-deps.patch"
mkdir -p "$SRC/ibyte" "$ROOT/.build/release-artifacts"
mkdir -p "$SRC/ibyte/restic" "$SRC/ibyte/cmd"
cp -R "$ROOT/wings-addon/ibyte/restic/." "$SRC/ibyte/restic/"
cp -R "$ROOT/wings-addon/cmd/." "$SRC/ibyte/cmd/"
trap 'git -C "$SRC" apply --reverse "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1-security-deps.patch"; git -C "$SRC" apply --reverse "$ROOT/wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch"' EXIT
cd "$SRC"
if [[ ${SKIP_RUNTIME_TESTS:-0} == 0 ]]; then RESTIC_TEST_BINARY=${RESTIC_TEST_BINARY:-$(command -v restic)} go test -race ./ibyte/restic/... ./ibyte/cmd/node-tool; fi
for arch in amd64 arm64; do
 GOOS=linux GOARCH=$arch CGO_ENABLED=0 go build -mod=readonly -trimpath -buildvcs=false -ldflags='-s -w -X github.com/pelican/wings/system.Version=1.0.0-rc.1+ibyte.3.0.0-preview.2' -o "$ROOT/.build/release-artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-$arch" .
 GOOS=linux GOARCH=$arch CGO_ENABLED=0 go build -mod=readonly -trimpath -buildvcs=false -ldflags='-s -w' -o "$ROOT/.build/release-artifacts/ibyte-node-tool-v3.0.0-preview.2-linux-$arch" ./ibyte/cmd/node-tool
done
go list -m -json all > "$ROOT/.build/release-artifacts/wings-modules.json"
cp LICENSE "$ROOT/.build/release-artifacts/PELICAN-WINGS-LICENSE.txt"
