#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
mkdir -p .upstream
fetch(){ local name=$1 url=$2 sha=$3; if [[ ! -d .upstream/$name/.git ]]; then git init ".upstream/$name"; git -C ".upstream/$name" remote add origin "$url"; git -C ".upstream/$name" fetch --depth 1 origin "$sha"; git -C ".upstream/$name" checkout --detach FETCH_HEAD; fi; [[ $(git -C ".upstream/$name" rev-parse HEAD) == "$sha" ]] || { echo "Wrong pinned source: $name" >&2; exit 1; }; }
fetch panel https://github.com/pelican/panel.git 46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00
fetch wings https://github.com/pelican/wings.git 7b489da7b8196d0b4baef3e2db062c06d743e2f9
fetch legacy https://github.com/Kowantify/Restic-Wings-V2.0.git 4ce6c042086f8e359ce5910422e02823b500ec12
fetch restic https://github.com/restic/restic.git 6aa3a516ce654808a1f28f9fa21e9b7c8e6e90bf
