#!/usr/bin/env bash
# Staging-only transactional installer. No repositories or server data are deleted.
set -Eeuo pipefail
umask 077
VERSION=3.0.0-preview.2
UPSTREAM=v1.0.0-rc.1
SERVICE=wings
CONFIG=/etc/pelican/config.yml
ADDON_CONFIG=/etc/pelican/ibyte-restic.json
STATE=/var/lib/ibyte-restic-installer
# Packaging fills these pins; administrators never need to copy a digest.
PINNED_NODE_MANIFEST_SHA256='__IBYTE_NODE_MANIFEST_SHA256__'
DEFAULT_RELEASE_URL='__IBYTE_DEFAULT_RELEASE_URL__'
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE=$SCRIPT_DIR
MANIFEST_SHA=$PINNED_NODE_MANIFEST_SHA256
MANIFEST_NAME=NODE-SHA256SUMS
PROFILE=
ACTION=install
ALLOW_PREVIEW=0
BIN=
RELEASE_URL=
NODE_ID=
REPO_ROOT=/var/lib/ibyte-restic/repositories
usage(){ echo "Usage: $0 --allow-preview --node-id NUMBER [--artifacts DIR] [--profile FILE] [--config FILE] [--binary FILE] [--service UNIT] [--rollback|--uninstall]"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
while (($#)); do
 case "$1" in
 --version) echo "$VERSION"; exit 0;;
 --allow-preview) ALLOW_PREVIEW=1; shift;;
 --yes) shift;;
 --artifacts) SOURCE=${2:?}; shift 2;;
 --manifest-sha256) MANIFEST_SHA=${2:?}; MANIFEST_NAME=SHA256SUMS; shift 2;;
 --release-url) RELEASE_URL=${2:?}; shift 2;;
 --profile) PROFILE=${2:?}; shift 2;;
 --node-id) NODE_ID=${2:?}; shift 2;;
 --repository-root) REPO_ROOT=${2:?}; shift 2;;
 --config) CONFIG=${2:?}; shift 2;;
 --binary) BIN=${2:?}; shift 2;;
 --service) SERVICE=${2:?}; shift 2;;
 --state) STATE=${2:?}; shift 2;;
 --rollback|--uninstall) ACTION=rollback; shift;;
 --help) usage; exit 0;;
 *) usage; die "Unknown argument $1";;
 esac
done
# Fixture relocation affects only installer-owned paths; tests use mocked systemd/identity.
# This is not a runtime daemon configuration override.
if [[ -n ${IBYTE_INSTALLER_FIXTURE_ROOT:-} ]]; then
 [[ $IBYTE_INSTALLER_FIXTURE_ROOT == /* && $IBYTE_INSTALLER_FIXTURE_ROOT != / ]] || die 'Invalid fixture root.'
 ADDON_CONFIG="$IBYTE_INSTALLER_FIXTURE_ROOT/etc/ibyte-restic.json"
fi
[[ $ALLOW_PREVIEW == 1 ]] || die "Production acceptance is blocked. Use --allow-preview only on disposable staging nodes."
[[ $(id -u) == 0 ]] || die "Run as root on the Wings node."
[[ $(uname -s) == Linux ]] || die "Linux only."
[[ $SERVICE =~ ^[a-zA-Z0-9_.@-]+$ ]] || die "Invalid systemd service."
for tool in systemctl sha256sum install mv cp mktemp flock readlink stat; do command -v "$tool" >/dev/null || die "Missing prerequisite: $tool"; done
[[ $STATE == /* && ! -L $STATE && $STATE != / && ! -L $CONFIG ]] || die "Unsafe state/config path."
install -d -m 0700 "$STATE"
exec 9>"$STATE/installer.lock"
flock -n 9 || die "Another installer owns the node."
[[ $(systemctl show "$SERVICE" --property=User --value) == '' || $(systemctl show "$SERVICE" --property=User --value) == root ]] || die "Only root systemd Wings units are supported."
EXEC_START=$(systemctl show "$SERVICE" --property=ExecStart --value)
if [[ -z $BIN ]]; then
 [[ $EXEC_START =~ path=([^\ \;]+) ]] || die "Cannot detect executable; provide --binary."
 BIN=${BASH_REMATCH[1]}
fi
[[ $BIN == /* && -f $BIN && ! -L $BIN ]] || die "Binary must be an absolute regular non-symlink."
[[ $EXEC_START == *"path=$BIN "* || $EXEC_START == *"path=$BIN;"* ]] || die "Selected binary is not systemd ExecStart."
[[ -f $CONFIG ]] || die "Wings config is missing."
# Fail rather than guess if unit uses a different config or unknown wrapper.
if [[ $EXEC_START == *--config* ]]; then
 [[ $EXEC_START == *"--config $CONFIG"* || $EXEC_START == *"--config=$CONFIG"* ]] || die "--config does not match systemd unit."
else
 [[ $CONFIG == /etc/pelican/config.yml ]] || die "Unit has no --config; only default config is valid."
fi
sha(){ sha256sum "$1" | awk '{print $1}'; }
atomic_install(){ local staged; staged=$(mktemp "$(dirname "$2")/.ibyte-install.XXXXXX"); install -m "$3" "$1" "$staged"; mv -f -- "$staged" "$2"; }
restart(){ systemctl restart "$SERVICE" && systemctl is-active --quiet "$SERVICE"; }
if [[ $ACTION == rollback ]]; then
 [[ -f $STATE/original && -f $STATE/original.sha256 && -f $STATE/installed.sha256 ]] || die "No verified rollback state."
 [[ $(sha "$STATE/original") == "$(cat "$STATE/original.sha256")" ]] || die "Rollback copy digest mismatch."
 [[ $(sha "$BIN") == "$(cat "$STATE/installed.sha256")" ]] || die "Current binary was changed externally; refusing overwrite."
 atomic_install "$STATE/original" "$BIN" 0755
 restart || die "Original binary restored, but service restart failed. Inspect systemd logs."
 rm -f -- "$STATE/installed.sha256"
 echo "Original Wings restored. Profiles, snapshots, data and state retained."
 exit 0
fi
case $(uname -m) in x86_64) ARCH=amd64; STOCK=19dda386e02041da1f1fbcc73f34e4bb01c1fbc2d499aac79a6be3a51ba7f0e8;; aarch64) ARCH=arm64; STOCK=ed7deafe0aef2acd3adae73103d2a01b57e8a7eaa56950194bdff11f87f37ff3;; *) die "Unsupported architecture.";; esac
[[ $MANIFEST_SHA =~ ^[a-f0-9]{64}$ ]] || die "This is an unpackaged installer template. Use install-wings-restic.sh from the generated node bundle or a published release."
TEMP_DIR=$(mktemp -d "$STATE/download.XXXXXX")
[[ $TEMP_DIR == "$STATE"/download.* ]] || die 'Temporary path escaped installer state.'
trap 'rm -rf -- "$TEMP_DIR"' EXIT
NAME="ibyte-pelican-wings-restic-$UPSTREAM-v$VERSION-linux-$ARCH"
HELPER="ibyte-node-tool-v$VERSION-linux-$ARCH"
RESTIC="restic-0.19.1-linux-$ARCH"
if [[ -z $RELEASE_URL && ! -f $SOURCE/$MANIFEST_NAME && $DEFAULT_RELEASE_URL == https://github.com/* ]]; then
 RELEASE_URL=$DEFAULT_RELEASE_URL
fi
if [[ -n $RELEASE_URL ]]; then
 [[ $RELEASE_URL =~ ^https://github.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/v[^/]+$ && ${RELEASE_URL##*/} == "v$VERSION" ]] || die "Expected an exact versioned GitHub release URL."
 command -v curl >/dev/null || die "curl is required for verified downloads."
 curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 60 --max-filesize 1048576 "$RELEASE_URL/$MANIFEST_NAME" -o "$TEMP_DIR/$MANIFEST_NAME"
 [[ $(sha "$TEMP_DIR/$MANIFEST_NAME") == "$MANIFEST_SHA" ]] || die 'Downloaded manifest digest mismatch.'
 for name in "$NAME" "$HELPER" "$RESTIC"; do curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 300 --max-filesize 209715200 "$RELEASE_URL/$name" -o "$TEMP_DIR/$name"; done
 SOURCE=$TEMP_DIR
fi
[[ -d $SOURCE && -f $SOURCE/$MANIFEST_NAME && $(sha "$SOURCE/$MANIFEST_NAME") == "$MANIFEST_SHA" ]] || die "Node release files are missing or modified. Extract the matching node bundle beside this installer, or use its published release URL."
verify(){ local expected; expected=$(awk -v name="$1" '$2 == name || $2 == "*" name {print $1}' "$SOURCE/$MANIFEST_NAME"); [[ $expected =~ ^[a-f0-9]{64}$ && -f $SOURCE/$1 && $(sha "$SOURCE/$1") == "$expected" ]] || die "Artifact verification failed: $1"; }
verify "$NAME"; verify "$HELPER"; verify "$RESTIC"
CURRENT=$(sha "$BIN")
TARGET=$(sha "$SOURCE/$NAME")
if [[ $CURRENT != "$STOCK" && (! -f $STATE/installed.sha256 || $CURRENT != "$(cat "$STATE/installed.sha256")") ]]; then die "Unknown/custom Wings binary; preserving it."; fi
# Stage helper before execution only after the trusted manifest was verified.
install -m 0700 "$SOURCE/$HELPER" "$TEMP_DIR/node-tool"
if [[ -n $PROFILE ]]; then
 [[ -f $PROFILE && ! -L $PROFILE ]] || die "Profile JSON unavailable."
 CANDIDATE="$TEMP_DIR/profile.json"
 install -m 0600 "$PROFILE" "$CANDIDATE"
else
 CANDIDATE=$ADDON_CONFIG
fi
# Restic is a private, release-pinned binary; never replace a system Restic installation.
RESTIC_TARGET="${IBYTE_INSTALLER_FIXTURE_ROOT:-}/opt/ibyte-restic/restic"
install -d -m 0700 "$(dirname "$RESTIC_TARGET")"
if [[ -e $RESTIC_TARGET && $(sha "$RESTIC_TARGET") != "$(sha "$SOURCE/$RESTIC")" ]]; then die "Unexpected private Restic binary; refusing replacement."; fi
atomic_install "$SOURCE/$RESTIC" "$RESTIC_TARGET" 0700
if [[ ! -f $CANDIDATE ]]; then
 [[ $NODE_ID =~ ^[1-9][0-9]*$ ]] || die "First installation requires --node-id NUMBER or --profile FILE."
 CANDIDATE="$TEMP_DIR/generated-profile.json"
 "$TEMP_DIR/node-tool" --mode configure --yes --node-id "$NODE_ID" --local-root "$REPO_ROOT" --config "$CONFIG" --addon "$CANDIDATE"
fi
"$TEMP_DIR/node-tool" --mode preflight --config "$CONFIG" --addon "$CANDIDATE" || die "Node preflight failed."
DATA_ROOT=$("$TEMP_DIR/node-tool" --mode data-root --config "$CONFIG")
[[ -d $DATA_ROOT && ! -L $DATA_ROOT ]] || die "Detected data root is missing or symlinked."
if [[ $CURRENT == "$TARGET" ]]; then
 [[ -z $PROFILE || $(sha "$PROFILE") == "$(sha "$ADDON_CONFIG")" ]] || die "Same-release config change refused; use explicit upgrade procedure."
 "$TEMP_DIR/node-tool" --mode health --config "$CONFIG" --addon "$ADDON_CONFIG"
 echo "Already installed and healthy: $VERSION"; exit 0
fi
[[ -z $PROFILE || ! -e $ADDON_CONFIG || $(sha "$PROFILE") == "$(sha "$ADDON_CONFIG")" ]] || die "Existing addon configuration differs; preserving it."
if [[ ! -f $STATE/original ]]; then
 install -m 0700 "$BIN" "$STATE/original"
 sha "$BIN" > "$STATE/original.sha256"
 install -m 0600 "$CONFIG" "$STATE/wings-config.backup"
 systemctl cat "$SERVICE" > "$STATE/wings-unit.backup"
fi
install -m 0700 "$BIN" "$STATE/previous"
ROLLBACK=1
rollback_on_error(){ local code=$?; trap - ERR; if [[ ${ROLLBACK:-0} == 1 ]]; then atomic_install "$STATE/previous" "$BIN" 0755; restart || true; echo 'Deployment failed; previous Wings restored. Inspect service status.' >&2; fi; exit "$code"; }
trap rollback_on_error ERR
if [[ ! -e $ADDON_CONFIG ]]; then install -d -m 0750 "$(dirname "$ADDON_CONFIG")"; atomic_install "$CANDIDATE" "$ADDON_CONFIG" 0600; fi
atomic_install "$SOURCE/$NAME" "$BIN" 0755
restart
healthy=0
for attempt in {1..12}; do
 if "$TEMP_DIR/node-tool" --mode health --config "$CONFIG" --addon "$ADDON_CONFIG"; then healthy=1; break; fi
 sleep 2
done
[[ $healthy == 1 ]]
printf '%s\n' "$TARGET" > "$STATE/installed.sha256"
ROLLBACK=0
echo "Installed staging preview $VERSION; original Wings retained at $STATE/original."
