#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
fixture=$(mktemp -d "$ROOT/.build/bootstrap.XXXXXXXX")
[[ $fixture == "$ROOT"/.build/bootstrap.* ]] || exit 1
trap 'rm -rf -- "$fixture"' EXIT
export IBYTE_BOOTSTRAP_FIXTURE=$fixture
case $(uname -m) in x86_64) arch=amd64;; aarch64) arch=arm64;; *) exit 1;; esac
for platform in amd64 arm64; do
 touch "$fixture/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-$platform" "$fixture/ibyte-node-tool-v3.0.0-preview.2-linux-$platform" "$fixture/restic-0.19.1-linux-$platform"
done
(cd "$fixture"; sha256sum ibyte-*-linux-* restic-*-linux-*) > "$fixture/NODE-SHA256SUMS"
cat > "$fixture/install-wings-restic.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
test "$1" = --artifacts
test -f "$2/NODE-SHA256SUMS"
test "$3" = --node-id && test "$4" = 23
test "$5" = --profile && test "$6" = 'literal $(not-executed)'
echo ran > "$IBYTE_BOOTSTRAP_FIXTURE/result"
SH
installer_sha=$(sha256sum "$fixture/install-wings-restic.sh" | cut -d' ' -f1)
node_sha=$(sha256sum "$fixture/NODE-SHA256SUMS" | cut -d' ' -f1)
sed -e "s/__NODE_SHA__/$node_sha/" -e "s/__INSTALLER_SHA__/$installer_sha/" -e 's|__DEFAULT_URL__||' "$ROOT/scripts/bootstrap-wings.sh" > "$fixture/install.sh"
bash "$fixture/install.sh" --artifacts "$fixture" --node-id 23 --profile 'literal $(not-executed)'
test "$(cat "$fixture/result")" = ran
echo 'PASS: plain-text bootstrap verifies files and preserves literal arguments'
rm "$fixture/result"
mkdir "$fixture/remote" "$fixture/bin"
cp "$fixture/install-wings-restic.sh" "$fixture/NODE-SHA256SUMS" "$fixture/remote/"
cp "$fixture/"*-linux-* "$fixture/remote/"
cat > "$fixture/bin/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
output= url=
while (($#)); do
 case "$1" in
  --output) output=$2; shift 2;;
  --proto|--proto-redir|--connect-timeout|--max-time|--max-filesize|--write-out) shift 2;;
  https://*) url=$1; shift;;
  *) shift;;
 esac
done
if [[ $url == https://github.com/pelican/wings/releases/latest ]]; then
 printf '%s' "https://github.com/pelican/wings/releases/tag/${IBYTE_FIXTURE_LATEST:-v1.0.0-rc.1}"
else
 [[ $url == https://github.com/fixture/addon/releases/download/v3.0.0-preview.2/* ]] || exit 1
 cp "$IBYTE_BOOTSTRAP_FIXTURE/remote/${url##*/}" "$output"
fi
SH
chmod +x "$fixture/bin/curl"
PATH="$fixture/bin:$PATH" bash "$fixture/install.sh" --repository fixture/addon --node-id 23 --profile 'literal $(not-executed)'
test "$(cat "$fixture/result")" = ran
rm "$fixture/result"
echo 'PASS: GitHub bootstrap downloads tested pair and runs only verified installer'
if IBYTE_FIXTURE_LATEST=v9.0.0 PATH="$fixture/bin:$PATH" bash "$fixture/install.sh" --repository fixture/addon > "$fixture/error" 2>&1; then exit 1; fi
test ! -f "$fixture/result"
grep -q 'has not been tested' "$fixture/error"
echo 'PASS: newer untested official Wings is refused before installation'
IBYTE_FIXTURE_LATEST=v9.0.0 PATH="$fixture/bin:$PATH" bash "$fixture/install.sh" --repository fixture/addon --node-id 23 --profile 'literal $(not-executed)' --rollback
test "$(cat "$fixture/result")" = ran
rm "$fixture/result"
echo 'PASS: newer upstream cannot prevent verified rollback of the original daemon'
echo tamper >> "$fixture/remote/NODE-SHA256SUMS"
if PATH="$fixture/bin:$PATH" bash "$fixture/install.sh" --repository fixture/addon > "$fixture/error" 2>&1; then exit 1; fi
test ! -f "$fixture/result"
grep -q 'Verification failed' "$fixture/error"
echo 'PASS: changed remote manifest is refused before execution'
echo tamper >> "$fixture/install-wings-restic.sh"
if bash "$fixture/install.sh" --artifacts "$fixture" --node-id 23 > "$fixture/error" 2>&1; then exit 1; fi
test ! -f "$fixture/result"
grep -q 'Verification failed' "$fixture/error"
echo 'PASS: modified installer rejected before execution'
if bash "$fixture/install.sh" --repository '../invalid' > "$fixture/error" 2>&1; then exit 1; fi
grep -q 'Invalid GitHub' "$fixture/error"
echo 'PASS: repository traversal rejected before network access'
mkdir -p "$fixture/copied folder/payload"
cp "$fixture/remote/install-wings-restic.sh" "$fixture/copied folder/payload/"
cp "$fixture/NODE-SHA256SUMS" "$fixture/"*-linux-* "$fixture/copied folder/payload/"
cp "$fixture/install.sh" "$fixture/copied folder/install.sh"
cat > "$fixture/bin/curl" <<'SH'
#!/usr/bin/env bash
echo 'Unexpected network call from copied local folder' >&2
exit 91
SH
(cd /; PATH="$fixture/bin:$PATH" bash "$fixture/copied folder/install.sh" --node-id 23 --profile 'literal $(not-executed)')
test "$(cat "$fixture/result")" = ran
rm "$fixture/result"
echo 'PASS: copied folder discovers payload from an unrelated working directory with network unavailable'
for platform in amd64 arm64; do
 cat > "$fixture/bin/uname" <<SH
#!/usr/bin/env bash
echo $([[ $platform == amd64 ]] && echo x86_64 || echo aarch64)
SH
 chmod +x "$fixture/bin/uname"
 PATH="$fixture/bin:$PATH" bash "$fixture/copied folder/install.sh" --verify-only
done
rm "$fixture/bin/uname"
echo 'PASS: copied payload verifies both supported architecture selections'
printf tamper >> "$fixture/copied folder/payload/restic-0.19.1-linux-$arch"
if PATH="$fixture/bin:$PATH" bash "$fixture/copied folder/install.sh" --verify-only > "$fixture/error" 2>&1; then exit 1; fi
grep -q 'Verification failed' "$fixture/error"
test ! -f "$fixture/result"
echo 'PASS: verify-only rejects a modified bundled binary without running the installer'
mv "$fixture/copied folder/payload" "$fixture/copied folder/payload-saved"
if PATH="$fixture/bin:$PATH" bash "$fixture/copied folder/install.sh" --verify-only > "$fixture/error" 2>&1; then exit 1; fi
grep -q 'Copy the entire Wings Installer folder' "$fixture/error"
echo 'PASS: missing copied payload gives an actionable error and never silently downloads'
test $(wc -c < "$ROOT/release/Wings Installer/install.sh") -lt 6000
bash "$ROOT/release/Wings Installer/install.sh" --artifacts "$ROOT/.build/release-artifacts" --verify-only
if [[ -d $ROOT/release/'Wings Installer'/payload ]]; then
 (cd /; PATH="$fixture/bin:$PATH" bash "$ROOT/release/Wings Installer/install.sh" --verify-only)
fi
echo 'PASS: public installer is small readable text and verifies actual staged release'
