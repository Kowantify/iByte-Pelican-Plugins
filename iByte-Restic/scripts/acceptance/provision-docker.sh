#!/usr/bin/env bash
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && -f /opt/ibyte-acceptance/fixture ]] || exit 1
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y docker.io
systemctl start docker
docker version
docker info --format '{{.ServerVersion}} / {{.Driver}}'
