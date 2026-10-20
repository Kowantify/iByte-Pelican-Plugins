#!/usr/bin/env bash
# Central build only. Nodes receive precompiled, manifest-verified binaries.
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
SHA=6aa3a516ce654808a1f28f9fa21e9b7c8e6e90bf
SRC="$ROOT/.build/restic-runtime"
[[ $(go env GOVERSION) == go1.27.2 ]] || { echo 'Pinned Go 1.27.2 required' >&2; exit 1; }
if [[ ! -d $SRC/.git ]]; then git clone https://github.com/restic/restic.git "$SRC"; fi
[[ $(git -C "$SRC" rev-parse HEAD) == "$SHA" ]] || git -C "$SRC" checkout --detach "$SHA"
[[ -z $(git -C "$SRC" status --porcelain --untracked-files=no) ]] || { echo 'Restic build checkout must be clean' >&2; exit 1; }
PATCH="$ROOT/restic-runtime/patches/v0.19.1-security-deps.patch"
git -C "$SRC" apply --check "$PATCH"
git -C "$SRC" apply "$PATCH"
trap 'git -C "$SRC" apply --reverse "$PATCH"' EXIT
mkdir -p "$ROOT/.build/release-artifacts"
cd "$SRC"
# Match upstream's reduced-gRPC build; omit optional selfupdate so node runtime cannot update itself.
for arch in amd64 arm64; do
 GOOS=linux GOARCH=$arch CGO_ENABLED=0 go build -mod=readonly -trimpath -buildvcs=false -tags=disable_grpc_modules -ldflags='-s -w -X github.com/restic/restic/internal/global.Version=0.19.1' -o "$ROOT/.build/release-artifacts/restic-0.19.1-linux-$arch" ./cmd/restic
done
go list -m -json all > "$ROOT/.build/release-artifacts/restic-modules.json"
cp LICENSE "$ROOT/.build/release-artifacts/RESTIC-LICENSE.txt"
