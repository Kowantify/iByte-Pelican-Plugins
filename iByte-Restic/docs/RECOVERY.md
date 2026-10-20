# Admin repository keys and retained server recovery

This revision implements the owner's explicit request for root-admin key visibility and former-owner inventory. It supersedes the earlier node-only/no-reveal/no-retained-recovery scope for **new v3 repositories**. There is no old Pterodactyl repository scan/import, automatic key rotation, repository wipe or automatic recreation of a deleted server. Production gates remain blocked.

## View keys and identify a former client

After updating both node and plugin, open **Restic Backups > Administrative** (`/admin/restic-backups/administrative`). This is a root-administrator-only inventory, independent of live Server/User/Node/BackupHost records. Customers and other admin roles cannot list/reveal it. Search the owner username/history, server name or server UUID.

Each repository records:

- Owner username, numeric user ID, UUID and dated ownership history, retained across ownership changes and account deletion. Names are captured metadata, not directory identifiers.
- Server name, numeric ID and UUID; original game-data root; original node ID/name/FQDN and host ID/name.
- Backend/profile, exact repository location, original password-file location, and S3 credential-file location where applicable.
- Key-capture time, server-deletion time, native backup UUID/name, Restic snapshot ID, owner at snapshot capture, lock state, size counters and explicit snapshot-forget time.

The default local location remains `/var/lib/ibyte-restic/repositories/host-H/node-N/SERVER-UUID`. Renaming folders to mutable usernames would break identity and moves; the durable inventory makes these UUID paths searchable by former owner instead. Ownership history is captured at escrow and on server owner/name changes; it is not a historical reconstruction of metadata lost before this feature existed.

Select **View keys** on a row and re-enter the current Panel password. A separate HTML view displays the repository password and recovery JSON. **Key history** uses the same password check and reveals all captured credential versions, including deleted-server repositories. **Download recovery kit** includes that history and uses the same password check and produces a confidential JSON attachment. Grants expire after 30 seconds, bind to the requesting root admin and repository, and can be used only once. Five password-confirmation attempts per minute are allowed. The secret response disables caching/referrer forwarding; HTML escapes names/paths. Credentials are not serialized into Livewire state or ordinary inventory JSON, and access audit rows contain actor/repository IDs and event names, not key values.

Use HTTPS for Panel login/reauthentication as well as Wings transport. The reveal/download controller explicitly refuses plaintext HTTP without consuming its grant. Reverse proxies must preserve the Panel's normal trusted HTTPS request handling; the plugin never disables TLS checks to work around a proxy problem.

The kit includes exact password-file bytes as `secret.password_base64`, preserving newline/binary content; the HTML also renders a readable UTF-8 password when possible. For S3, `secret.credentials_base64` contains the approved private `key`/`secret` JSON. Base64 is encoding, **not encryption**: a downloaded kit is confidential. Accounts without a known current Panel password cannot use this reveal path. No rotation button is provided.

## Unique server keys and automatic history

New repositories use independent random 256-bit passwords for each host/node/profile/server identity. Private node-state files contain the password and a durable upgrade journal; they stay outside game data and repositories. S3 account credentials remain approved storage-profile credentials, distinct from the repository password.

Updating an existing v3 shared-profile repository adds and verifies a unique password wrapper without re-encrypting backup content. Before removing the original shared wrapper, Panel commits encrypted current/prior credentials and key history. The native queue or automatic synchronization job sends a matching escrow receipt afterward. A crash can resume this two-phase operation; no new backup is dispatched until unique-key activation succeeds. No arbitrary old Pterodactyl repository adoption is performed. Keep node private state, profile files and complete repositories during upgrades.

Creation automatically prepares and escrows keys before dispatch; completion records the full snapshot mapping. The existing Panel scheduler backfills active repositories every five minutes when escrow/statistics are missing or stale. Leave the normal scheduler and durable queue running. Node updates are required before this plugin update can create backups. Unknown/missing nodes fail closed. Old nodes do not silently continue with shared passwords.

Panel encrypts credentials/history with Laravel Crypt and APP_KEY, without cascading server/user/node/host foreign keys. Preserve the database and original APP_KEY separately. Deleted/offline repositories remain viewable using saved escrow; a key never captured before data loss cannot be reconstructed. Historical passwords may unlock earlier repository copies only if those copies still contain their corresponding keys/ wrapper. There is no manual rotation button. Unexpected external location/credential changes remain blocked.

## Delete or transfer a server: retain only the latest backup

The native admin Delete (including force) confirmation defaults to keeping a fresh final backup. Transfer uses the same retention policy and warns that Restic backups do not transfer. Root administrators confirm with their current Panel password; the authorized server record supplies identity internally, without typing a UUID. The optional Keep latest Restic backup and delete server shortcut applies the same policy. Other backup providers retain native Pelican behavior. Direct unconfirmed deletion/transfer remains guarded.

The plugin keeps the latest successful completed Restic snapshot globally for that server, determined by creation time then numeric backup ID. preview.2 first prepares a fresh locked native backup, preserves the server and requires a second password-confirmed action within 15 minutes after verified completion. A free quota slot is required so preparation cannot evict the prior recovery point. An older locked backup, unfinished backup, unresolved operation, missing snapshot mapping or unavailable fresh escrow blocks the action. The latest snapshot may remain locked. Explicitly unlock older protected snapshots first; automatic cleanup never unlocks them.

Fresh escrow commits before external destructive calls. Under a native server-row lock, older unlocked snapshots are forgotten while Wings still knows the source server. A durable reservation blocks competing backup/maintenance work during the transition. For transfers, the native ServerTransfer creation transaction detaches the retained source backup row and commits before notifying Wings; callbacks then update the destination node without repointing old Restic backups. Source keys, former owner and snapshot identity remain under Restic Backups. New destination backups use a separate node repository/key. If destination storage is not configured, configure its approved Restic host normally.

Older snapshot removal cannot be undone if a later native delete/transfer fails. A failed transfer setup preserves the latest native source row; an interrupted reserved transition requires a root administrator to retry the confirmed action after checking native transfer state. Uncertain node work must be reconciled first. Raw SQL/direct daemon calls bypass plugin guards. MariaDB/Redis distributed races and real multi-node runtime acceptance remain unverified.

Only the retained snapshot remains referenced. Unreferenced packs may still occupy disk: lifecycle cleanup suppresses delayed prune because native server authentication disappears on source deletion. Retained check/statistics/compaction can be enabled under Administrative > Automatic retained maintenance through the existing scheduler, even without a live source server; snapshot expiry remains unimplemented; never interpret forget as secure physical erasure. Normal backup deletion still queues automatic coalesced pruning. Complete retained repositories and their keys/ files must remain available for recovery.

After deletion or transfer, Administrative > View keys, Key history and Download recovery kit work without source-node access. Repository inventory identifies the source node, former owner, lifecycle reason and destination node where recorded. No automatic expiry, cross-node backup relocation or recreation/adoption of a deleted server is provided.

## Delete the server and all its repositories

In the native admin server Delete dialog, choose **Force delete server and destroy all Restic repositories** and enter your current Panel password. No UUID, final backup, new escrow preparation or individual snapshot deletion is required. This permanently discards backups, including locked and unrecognized snapshots. The default remains **Keep a fresh final backup**.

Before native deletion, Panel commits an immutable plan covering all captured local repositories for the server, including retained sources on old nodes. Native force deletion then removes the Panel server even if its daemon cannot be reached. The durable queue submits each repository to its original authenticated node using the saved generation/location fingerprint. A missing server is supported; active addon jobs, unsafe paths and unresolved node restore journals still refuse removal. External Restic writers must be stopped first.

The UI reports **destruction queued**, never physical success before node confirmation. Offline or refusing nodes remain **Destruction pending**, with an administrator alert and automatic queue/scheduler retries. Keep the existing Panel queue and scheduler running. No extra cron is needed. If native deletion itself is interrupted while the server still exists, retry the same option with your password; the committed plan is reused.

Combined destruction requires captured local repository locations and no active transfer. S3 physical deletion and missing inventory/location are refused before deleting the server. Credentials and owner/key history remain encrypted for audit and older external repository copies; they cannot recover backup files that have been destroyed. Ordinary snapshot deletion and retained-mode failures preserve their metadata when removal cannot be confirmed.

## Restore into a separate empty directory

For a returning client, identify the old user/server in the inventory, download its recovery kit and retain the complete repository. Use the recorded full snapshot ID. Recovery is an operator task into a separate empty directory; create a new Pelican server through normal provisioning and deliberately transfer verified recovered files afterward. A new server UUID is not automatically attached to the old repository, and old owner IDs do not grant a new account access automatically.

Example for the supported Linux node, a securely downloaded **local** kit and verified Restic 0.19.1. These commands were not run against an operator node; the equivalent encrypted-repository recovery is tested in disposable Windows fixtures. `jq` is needed for this optional manual kit extraction, not for node installation.

```bash
sudo -i
umask 077
set -euo pipefail
KIT=/root/restic-recovery-42.json
jq -r '.secret.password_base64' "$KIT" | base64 --decode > /root/restic-recovery.password
RESTIC_REPO=$(jq -r '.repository' "$KIT")
ORIGINAL_ROOT=$(jq -r '.metadata.original_root' "$KIT")
/opt/ibyte-restic/restic --repo "$RESTIC_REPO" --password-file /root/restic-recovery.password snapshots

# Select an actual full snapshot ID from the kit / snapshots output.
SNAPSHOT='REPLACE_WITH_FULL_SNAPSHOT_ID'
TARGET=/root/restic-recovery-empty
test ! -e "$TARGET"
mkdir -m 0700 "$TARGET"
/opt/ibyte-restic/restic --repo "$RESTIC_REPO" --password-file /root/restic-recovery.password \
  restore "$SNAPSHOT:$ORIGINAL_ROOT" --target "$TARGET" --verify
```

Stop if the target already exists; choose another empty directory. Use the snapshot's original root/subfolder to recover only game-server contents, rather than ancestor filesystem metadata. On other platforms derive that snapshot-tree path with `restic ls`; Windows tree paths differ. Keep the password in its protected file, not command arguments.

For S3, first decode `secret.credentials_base64` into a private file and set `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` from its `key`/`secret` fields in the privileged recovery environment. Use the recorded HTTPS repository URI and preserve normal certificate verification. Real remote S3 recovery is not locally verified; its credential-descriptor export is tested without network access.

## Verification limits

Actual local tests cover encrypted escrow, root-only list/reveal, password failure/rate limit, expired/replayed/wrong-user grants, no-cache/escaping, audit redaction, ownership changes, native retained deletion/cascade with mocked daemon, saved key access after server/user deletion and an offline node, plus real Restic byte-for-byte restoration after original game data, credential file and node state removal. Native plugin/resource APIs are used; no Panel core patch or alternate customer backups page is installed.

Linux middleware tests for the new private route are cross-compiled, not executed here. Actual browser installation/reveal, Linux/systemd retained deletion, MariaDB/distributed races and real S3 recovery still require disposable acceptance testing. The supported native restore API/write fence and dependency/publication gates remain blocked. This feature adds recoverability evidence, not production certification.

## Finding controls and deleting a local repository

In Admin > Restic Backups > Administrative, View keys, Key history and Remove Panel records lead each row. More contains recovery-kit download and Delete entire repository. Servers contains structured identity/operations details; Stats contains saved measurements and metric definitions. These tabs share one sidebar entry. Explicit button/grouped views keep labels readable under either icon preference. Extra path/generation columns are optional; force cleanup is exclusively administrative.

Remove Panel records requires the current root admin password and discards all native records and retained snapshot references for that source host/node/server. It overrides locks, recent/pending status and lifecycle reservations without any node I/O. The header Remove stale Panel record handles one selected backup, including servers without inventory. Secrets/history remain; server restore protection and unresolved restore evidence are not cleared. Already running node work cannot be cancelled by deleting SQL metadata.

Delete entire repository requires only the current root admin password after the permanent-loss warning. It overrides backup locks, unknown snapshots, missing markers/mappings and damaged Restic credentials/config. The matching source node must advertise local-v1 force deletion. The node computes its approved local leaf from immutable identity and verifies the captured location fingerprint, protects native game/backup/state roots, refuses active addon jobs, and retires the identity before removal. Retained local sources after server deletion/transfer are supported without a live ServerExists lookup. Physical S3 deletion is not implemented. No arbitrary path is accepted. External Restic writers must be stopped by the operator.

A durable force intent commits before node I/O. A timeout or wrong-identity response leaves Panel records and keys intact. Retry the same password-confirmed action to reconcile its persisted request UUID. Unknown restore journals/protection remain untouched. Only verified physical success removes native records and marks repository storage Removed; encrypted keys and owner history remain. Historical counters are not current bytes after deletion. Installation/update never wipes repositories.

## Creating another backup after repository deletion

Use the same backup host and the normal native Create backup action or schedule. With the matching updated Wings build, verified absence of an initialized local repository automatically starts the next numbered generation with a fresh random key. No new host, key-reset action or manual configuration is required. Generation zero keeps its original path; replacements use sibling leaves named SERVER_UUID--g1, --g2 and so on, with separate private key/mapping/job identities. A delayed deletion of generation zero cannot erase generation one.

Old passwords remain in encrypted admin Key history with the location and generation they belonged to. Current key/location point to the replacement. Existing manually deleted backup records are retained for audit until explicitly removed; normal quotas and permissions still apply. The scheduler follows the same creation/escrow path. Fresh escrow commits before node dispatch, and queued first attempts/transaction rollback reuse the prepared generation instead of generating more keys.

Only a successful authenticated local state probe establishes absence. A path that still exists, permissions/TLS/network errors, active jobs and unresolved restores are not treated as deletion; no existing path is wiped to renew. S3 provisioning remains explicit. Old nodes without repository_generations=local-v1 receive an update instruction after confirmed deletion. Update Wings first, import the matching Panel ZIP over the existing plugin, restart the actual queue worker, then create normally. Real Linux/browser/distributed acceptance remains outstanding.
