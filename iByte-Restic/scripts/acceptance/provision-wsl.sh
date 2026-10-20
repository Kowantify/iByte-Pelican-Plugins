#!/usr/bin/env bash
# Developer-only disposable WSL test environment, never an operator installer.
set -euo pipefail
[[ ${IBYTE_DISPOSABLE_LINUX:-} == 1 && $(uname -r) == *microsoft* && $EUID == 0 ]] || { echo 'Explicit disposable WSL root fixture required.' >&2; exit 1; }
case ${1:-} in
 configure)
  printf '[boot]\nsystemd=true\n[interop]\nappendWindowsPath=false\n' > /etc/wsl.conf
  mkdir -p /opt/ibyte-acceptance
  printf 'Disposable iByte acceptance fixture\n' > /opt/ibyte-acceptance/fixture
  ;;
 tools)
  [[ -f /opt/ibyte-acceptance/fixture ]] || exit 1
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y ca-certificates curl git systemd systemd-sysv dbus sudo openssl tar xz-utils file gcc libc6-dev software-properties-common
  add-apt-repository -y ppa:ondrej/php
  apt-get update
  apt-get install -y php8.5-cli php8.5-sqlite3 php8.5-mbstring php8.5-xml php8.5-intl php8.5-zip php8.5-curl
  php -v
  ;;
 *) echo 'Use configure, restart only the dedicated distribution, then tools.' >&2; exit 1;;
esac
