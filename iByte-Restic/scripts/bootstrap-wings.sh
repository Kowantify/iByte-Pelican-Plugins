#!/usr/bin/env bash
# iByte Restic: install the TESTED Wings pair from adjacent files or GitHub.
set -Eeuo pipefail
VERSION=3.0.0-preview.2
UPSTREAM=v1.0.0-rc.1
URL='__DEFAULT_URL__'
NODE_SHA='__NODE_SHA__'
INSTALLER_SHA='__INSTALLER_SHA__'
SOURCE= REPOSITORY= VERIFY=0 ROLLBACK=0
args=()
die(){ echo "ERROR: $*" >&2; exit 1; }
while (($#)); do
 case "$1" in
  --version) echo "$VERSION"; exit 0;;
  --help) echo 'Usage: sudo bash install.sh --allow-preview --node-id NUMBER [--repository OWNER/REPO] [--rollback]'; echo 'Without a repository, keep the adjacent payload folder; it contains all required binaries.'; exit 0;;
  --repository) REPOSITORY=${2:?}; shift 2;;
  --artifacts) SOURCE=${2:?}; shift 2;;
  --verify-only) VERIFY=1; shift;;
  --rollback|--uninstall) ROLLBACK=1; args+=("$1"); shift;;
  *) args+=("$1"); shift;;
 esac
done
for tool in sha256sum mktemp uname; do command -v "$tool" >/dev/null || die "Missing prerequisite: $tool"; done
if [[ -n $REPOSITORY ]]; then
 [[ $REPOSITORY =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*/[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || die 'Invalid GitHub repository.'
 URL="https://github.com/$REPOSITORY/releases/download/v$VERSION"
fi
# A copied unpublished installer folder is self-contained, from any working directory.
if [[ -z $SOURCE && -z $URL ]]; then
 adjacent="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/payload"
 [[ -d $adjacent ]] || die 'Local payload folder is missing. Copy the entire Wings Installer folder, including payload, then run install.sh again.'
 SOURCE=$adjacent
fi
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
case $(uname -m) in x86_64) arch=amd64;; aarch64) arch=arm64;; *) die 'Unsupported architecture.';; esac
files=(install-wings-restic.sh NODE-SHA256SUMS "ibyte-pelican-wings-restic-$UPSTREAM-v$VERSION-linux-$arch" "ibyte-node-tool-v$VERSION-linux-$arch" "restic-0.19.1-linux-$arch")
[[ $ROLLBACK == 0 ]] || files=(install-wings-restic.sh NODE-SHA256SUMS)
if [[ -z $SOURCE ]]; then
 [[ $URL == https://github.com/*/releases/download/v3.0.0-preview.2 ]] || die 'Release is not published yet. Supply --repository OWNER/REPO after publication, or --artifacts DIR for local testing.'
 command -v curl >/dev/null || die 'curl is required.'
 # New upstream versions block installation, never rollback of a verified original.
 if [[ $ROLLBACK == 0 ]]; then
  latest=$(curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 30 --output /dev/null --write-out '%{url_effective}' https://github.com/pelican/wings/releases/latest)
  [[ $latest == "https://github.com/pelican/wings/releases/tag/$UPSTREAM" ]] || die 'Latest upstream Wings has not been tested with this addon. Existing node preserved.'
 fi
 for file in "${files[@]}"; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 300 --max-filesize 209715200 "$URL/$file" --output "$work/$file"
 done
 SOURCE=$work
fi
check(){ printf '%s  %s\n' "$1" "$SOURCE/$2" | sha256sum --check --status || die "Verification failed: $2"; }
check "$INSTALLER_SHA" install-wings-restic.sh
check "$NODE_SHA" NODE-SHA256SUMS
# Verify the selected binaries before execution, including --verify-only.
for file in "${files[@]:2}"; do
 expected= matches=0
 while read -r digest name; do
  name=${name#\*}
  if [[ $name == "$file" ]]; then expected=$digest; matches=$((matches+1)); fi
 done < "$SOURCE/NODE-SHA256SUMS"
 [[ $matches == 1 && $expected =~ ^[a-f0-9]{64}$ ]] || die "Missing or ambiguous manifest entry: $file"
 check "$expected" "$file"
done
# The verified installer checks each executable and implements preflight/rollback.
if [[ $VERIFY == 1 ]]; then echo 'Installer, manifest and selected binaries verified; no node changes.'; exit 0; fi
bash "$SOURCE/install-wings-restic.sh" --artifacts "$SOURCE" "${args[@]}"
