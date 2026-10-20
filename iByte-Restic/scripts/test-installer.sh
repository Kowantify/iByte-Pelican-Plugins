#!/usr/bin/env bash
# No real root/systemd/node access. Every installer-owned path is inside a disposable fixture.
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
mkdir -p "$ROOT/.build"
FIXTURE=$(mktemp -d "$ROOT/.build/installer-test.XXXXXX")
[[ $FIXTURE == "$ROOT"/.build/installer-test.* ]] || exit 1
trap 'rm -rf -- "$FIXTURE"' EXIT
export IBYTE_INSTALLER_FIXTURE_ROOT="$FIXTURE"
export FIXTURE
REAL_SHA=$(command -v sha256sum)
export REAL_SHA
mkdir -p "$FIXTURE/mock" "$FIXTURE/etc" "$FIXTURE/bin" "$FIXTURE/artifacts" "$FIXTURE/data"
export TEST_BIN="$FIXTURE/bin/wings"
export TEST_CONFIG="$FIXTURE/etc/config.yml"
printf 'stock-pelican\n' > "$TEST_BIN"
printf 'config-preserve\n' > "$TEST_CONFIG"
printf '{}\n' > "$FIXTURE/profile.json"
printf 'server data must survive\n' > "$FIXTURE/data/world"
cat > "$FIXTURE/mock/id" <<'EOF'
#!/usr/bin/env bash
echo 0
EOF
cat > "$FIXTURE/mock/uname" <<'EOF'
#!/usr/bin/env bash
case "$1" in -s) echo Linux;; -m) echo x86_64;; *) exit 1;; esac
EOF
cat > "$FIXTURE/mock/systemctl" <<'EOF'
#!/usr/bin/env bash
set -eu
case "$1" in
 show) case "$*" in *--property=User*) echo root;; *--property=ExecStart*) printf '{ path=%s ; argv[]=%s --config %s ; }\n' "$TEST_BIN" "$TEST_BIN" "$TEST_CONFIG";; esac;;
 cat) echo 'fixture systemd unit';;
 restart) if [[ ${FAIL_START:-0} == 1 && $(cat "$TEST_BIN") == addon-binary ]]; then exit 1; fi; echo restart >> "$FIXTURE/restarts";;
 is-active) exit 0;;
 *) exit 1;;
esac
EOF
cat > "$FIXTURE/mock/sha256sum" <<'EOF'
#!/usr/bin/env bash
if [[ -f $1 && $(cat "$1") == stock-pelican ]]; then
 printf '19dda386e02041da1f1fbcc73f34e4bb01c1fbc2d499aac79a6be3a51ba7f0e8  %s\n' "$1"
else exec "$REAL_SHA" "$@"; fi
EOF
cat > "$FIXTURE/mock/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "$FIXTURE/mock/flock" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
if [[ $(command uname -s) == MINGW* ]]; then
 # Windows cannot demonstrate Linux ownership/mode semantics. Only relocate/copy here.
 cat > "$FIXTURE/mock/install" <<'EOF'
#!/usr/bin/env bash
set -eu
directory=0
while [[ $1 == -* ]]; do case "$1" in -d) directory=1;shift;; -m) shift 2;; *) exit 1;; esac; done
if [[ $directory == 1 ]]; then mkdir -p -- "$@"; else cp -- "$@"; fi
EOF
fi
cat > "$FIXTURE/artifacts/ibyte-node-tool-v3.0.0-preview.2-linux-amd64" <<'EOF'
#!/usr/bin/env bash
set -eu
case "$*" in
 *'--mode data-root'*) printf '%s' "$FIXTURE/data";;
 *'--mode health'*) [[ ${FAIL_HEALTH:-0} != 1 ]];;
 *'--mode preflight'*) [[ ${FAIL_PREFLIGHT:-0} != 1 ]];;
 *) exit 1;;
esac
EOF
printf 'addon-binary\n' > "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
printf 'private-restic\n' > "$FIXTURE/artifacts/restic-0.19.1-linux-amd64"
chmod +x "$FIXTURE/mock/"* "$FIXTURE/artifacts/ibyte-node-tool-v3.0.0-preview.2-linux-amd64"
export PATH="$FIXTURE/mock:$PATH"
make_manifest(){ (cd "$FIXTURE/artifacts"; "$REAL_SHA" ibyte-* restic-*) > "$FIXTURE/artifacts/SHA256SUMS"; MANIFEST=$("$REAL_SHA" "$FIXTURE/artifacts/SHA256SUMS" | awk '{print $1}'); }
make_manifest
run(){ bash "$ROOT/scripts/install-wings-restic.sh" --allow-preview --yes --binary "$TEST_BIN" --config "$TEST_CONFIG" --profile "$FIXTURE/profile.json" --state "$FIXTURE/state" --artifacts "$FIXTURE/artifacts" --manifest-sha256 "$MANIFEST" "$@"; }
assert_preserved(){ [[ $(cat "$TEST_CONFIG") == config-preserve && $(cat "$FIXTURE/data/world") == 'server data must survive' ]]; }
expect_failure(){ local expected=$1; shift; if run "$@" > "$FIXTURE/last-output" 2>&1; then cat "$FIXTURE/last-output"; echo 'Expected failure' >&2; exit 1; fi; if ! grep -q "$expected" "$FIXTURE/last-output"; then cat "$FIXTURE/last-output"; echo "Wrong failure; expected $expected" >&2; exit 1; fi; }

export FAIL_PREFLIGHT=1
expect_failure 'Node preflight failed'
[[ $(cat "$TEST_BIN") == stock-pelican ]]
unset FAIL_PREFLIGHT
echo 'PASS: preflight failure preserves original binary'
printf 'custom-binary\n' > "$TEST_BIN"
expect_failure 'Unknown/custom Wings'
[[ $(cat "$TEST_BIN") == custom-binary ]]
printf 'stock-pelican\n' > "$TEST_BIN"
echo 'PASS: unknown/custom binary refused'
run
[[ $(cat "$TEST_BIN") == addon-binary && $(cat "$FIXTURE/state/original") == stock-pelican ]]
assert_preserved
before=$(wc -l < "$FIXTURE/restarts")
run
[[ $(wc -l < "$FIXTURE/restarts") == "$before" ]]
echo 'PASS: unattended installation twice is repeat-safe without a second restart'
run --rollback
[[ $(cat "$TEST_BIN") == stock-pelican ]]
assert_preserved
echo 'PASS: rollback restores original and preserves game/config data'
export FAIL_START=1
expect_failure 'previous Wings restored'
unset FAIL_START
[[ $(cat "$TEST_BIN") == stock-pelican ]]
echo 'PASS: failed new daemon startup automatically restores previous binary'
export FAIL_HEALTH=1
expect_failure 'previous Wings restored'
unset FAIL_HEALTH
[[ $(cat "$TEST_BIN") == stock-pelican ]]
echo 'PASS: failed addon health automatically restores previous binary'
printf 'tampered' >> "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
expect_failure 'Artifact verification failed'
[[ $(cat "$TEST_BIN") == stock-pelican ]]
assert_preserved
echo 'PASS: tampered release binary refused before daemon replacement'
# Normal operator path: generated script, adjacent bundle, no digest flags.
printf 'addon-binary\n' > "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
for file in "$FIXTURE/artifacts/"ibyte-*-amd64 "$FIXTURE/artifacts/"restic-*-amd64; do cp "$file" "${file%-amd64}-arm64"; done
(cd "$FIXTURE/artifacts"; "$REAL_SHA" ibyte-* restic-*) > "$FIXTURE/artifacts/NODE-SHA256SUMS"
NODE_PIN=$("$REAL_SHA" "$FIXTURE/artifacts/NODE-SHA256SUMS" | awk '{print $1}')
make_auto_installer(){
 awk -v pin="$NODE_PIN" -v url="${2:-}" '{gsub(/__IBYTE_NODE_MANIFEST_SHA256__/,pin);gsub(/__IBYTE_DEFAULT_RELEASE_URL__/,url);print}' "$ROOT/scripts/install-wings-restic.sh" > "$1"
}
make_auto_installer "$FIXTURE/artifacts/install-wings-restic.sh"
auto_run(){ bash "$FIXTURE/artifacts/install-wings-restic.sh" --allow-preview --yes --binary "$TEST_BIN" --config "$TEST_CONFIG" --profile "$FIXTURE/profile.json" --state "$FIXTURE/auto-state" "$@"; }
expect_auto_failure(){ local expected=$1; shift; if auto_run "$@" > "$FIXTURE/last-output" 2>&1; then echo 'Expected automatic verification failure' >&2; exit 1; fi; if ! grep -q "$expected" "$FIXTURE/last-output"; then cat "$FIXTURE/last-output"; exit 1; fi; }
(cd "$FIXTURE/data"; auto_run)
[[ $(cat "$TEST_BIN") == addon-binary ]]
assert_preserved
echo 'PASS: bundled installer discovers adjacent files from another directory without hash arguments'
before=$(wc -l < "$FIXTURE/restarts")
auto_run
[[ $(wc -l < "$FIXTURE/restarts") == "$before" ]]
echo 'PASS: automatic verification preserves repeat-safe installation'
auto_run --rollback
[[ $(cat "$TEST_BIN") == stock-pelican ]]
echo 'PASS: bundled rollback needs no manifest or hash arguments'
printf 'tampered' >> "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
expect_auto_failure 'Artifact verification failed'
[[ $(cat "$TEST_BIN") == stock-pelican ]]
printf 'addon-binary\n' > "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
echo 'PASS: modified binaries rejected without operator-supplied digests'
cp "$FIXTURE/artifacts/NODE-SHA256SUMS" "$FIXTURE/node-manifest.original"
printf 'tampered' >> "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
(cd "$FIXTURE/artifacts"; "$REAL_SHA" ibyte-* restic-*) > "$FIXTURE/artifacts/NODE-SHA256SUMS"
expect_auto_failure 'Node release files are missing or modified'
[[ $(cat "$TEST_BIN") == stock-pelican ]]
cp "$FIXTURE/node-manifest.original" "$FIXTURE/artifacts/NODE-SHA256SUMS"
printf 'addon-binary\n' > "$FIXTURE/artifacts/ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-amd64"
echo 'PASS: changing both binary and manifest cannot bypass the embedded release pin'
mkdir -p "$FIXTURE/standalone"
make_auto_installer "$FIXTURE/standalone/install-wings-restic.sh" 'https://github.com/fixture/ibyte/releases/download/v3.0.0-preview.2'
cat > "$FIXTURE/mock/curl" <<'EOF'
#!/usr/bin/env bash
set -eu
out=; url=
while (($#)); do
 case "$1" in
 -o) out=$2;shift 2;;
 https://*) url=$1;shift;;
 --proto|--proto-redir|--connect-timeout|--max-time|--max-filesize) shift 2;;
 *) shift;;
 esac
done
[[ -n $out && $url == https://github.com/fixture/ibyte/releases/download/v3.0.0-preview.2/* ]] || exit 1
printf '%s\n' "${url##*/}" >> "$FIXTURE/downloads"
cp "$FIXTURE/artifacts/${url##*/}" "$out"
EOF
chmod +x "$FIXTURE/mock/curl"
bash "$FIXTURE/standalone/install-wings-restic.sh" --allow-preview --yes --binary "$TEST_BIN" --config "$TEST_CONFIG" --profile "$FIXTURE/profile.json" --state "$FIXTURE/auto-state"
[[ $(head -n 1 "$FIXTURE/downloads") == NODE-SHA256SUMS && $(wc -l < "$FIXTURE/downloads") == 4 && $(cat "$TEST_BIN") == addon-binary ]]
assert_preserved
echo 'PASS: standalone installer downloads pinned files without hash or URL arguments'
auto_run --rollback
printf '\n' >> "$FIXTURE/artifacts/NODE-SHA256SUMS"
: > "$FIXTURE/downloads"
if bash "$FIXTURE/standalone/install-wings-restic.sh" --allow-preview --yes --binary "$TEST_BIN" --config "$TEST_CONFIG" --profile "$FIXTURE/profile.json" --state "$FIXTURE/auto-state" > "$FIXTURE/last-output" 2>&1; then exit 1; fi
grep -q 'Downloaded manifest digest mismatch' "$FIXTURE/last-output"
[[ $(wc -l < "$FIXTURE/downloads") == 1 && $(cat "$TEST_BIN") == stock-pelican ]]
cp "$FIXTURE/node-manifest.original" "$FIXTURE/artifacts/NODE-SHA256SUMS"
assert_preserved
echo 'PASS: modified download manifest stops installation before downloading or running executable artifacts'
# Exercise the public entry point with a copied complete folder and real inner
# installer, using only the same disposable systemd/helper/binary fixtures.
mkdir -p "$FIXTURE/copied Wings/payload"
cp "$FIXTURE/artifacts/"*-linux-* "$FIXTURE/artifacts/NODE-SHA256SUMS" "$FIXTURE/artifacts/install-wings-restic.sh" "$FIXTURE/copied Wings/payload/"
INNER_PIN=$("$REAL_SHA" "$FIXTURE/copied Wings/payload/install-wings-restic.sh" | awk '{print $1}')
awk -v manifest="$NODE_PIN" -v installer="$INNER_PIN" '{gsub(/__NODE_SHA__/,manifest);gsub(/__INSTALLER_SHA__/,installer);gsub(/__DEFAULT_URL__/,"");print}' "$ROOT/scripts/bootstrap-wings.sh" > "$FIXTURE/copied Wings/install.sh"
bundle_run(){ bash "$FIXTURE/copied Wings/install.sh" --allow-preview --node-id 23 --binary "$TEST_BIN" --config "$TEST_CONFIG" --profile "$FIXTURE/profile.json" --state "$FIXTURE/auto-state" "$@"; }
: > "$FIXTURE/downloads"
(cd /; bundle_run)
[[ $(cat "$TEST_BIN") == addon-binary && ! -s $FIXTURE/downloads ]]
assert_preserved
echo 'PASS: copied public Wings folder installs through the real inner installer without downloads or artifacts/hash flags'
before=$(wc -l < "$FIXTURE/restarts")
bundle_run
[[ $(wc -l < "$FIXTURE/restarts") == "$before" ]]
echo 'PASS: copied public Wings folder repeat install is safe'
bundle_run --rollback
[[ $(cat "$TEST_BIN") == stock-pelican ]]
assert_preserved
echo 'PASS: copied public Wings folder rolls back and preserves data/config'

echo 'Installer fixture suite passed: 17 cases (mock systemd/identity/helper/downloads; no Linux runtime claim)'
