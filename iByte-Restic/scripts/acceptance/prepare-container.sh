#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture ]] || exit 1
SERVER=11111111-1111-4111-8111-111111111111
IMAGE=busybox:1.37.0@sha256:bdf57e528e45e4433820e045b29b4597825a1c9e38353532d90a01445013f82e
if docker container inspect "$SERVER" >/dev/null 2>&1; then
 [[ $(docker inspect --format '{{index .Config.Labels "ibyte.fixture"}}' "$SERVER") == true ]] || { echo 'Unknown fixture container; refusing to use it.' >&2; exit 1; }
 [[ $(docker inspect --format '{{.State.Running}}' "$SERVER") == false ]] || { echo 'Fixture container must already be stopped.' >&2; exit 1; }
else
 docker pull "$IMAGE"
 docker create --label ibyte.fixture=true --name "$SERVER" "$IMAGE" /bin/true >/dev/null
fi
docker image inspect "$IMAGE" --format '{{json .RepoDigests}}'
echo 'Disposable container exists and is stopped; no game process was started.'
