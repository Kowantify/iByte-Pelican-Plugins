# Tests and compatibility

This is a staging candidate. Tests use disposable local files/databases and fake credentials. No production connection or deployment was made. Earlier reports are archived in archive/; current key/lifecycle output is in evidence/server-keys-*.txt; previous deletion-fix output is in evidence/server-keys-*.txt; earlier refactor evidence remains in evidence/refactor-*.txt.

## Latest evidence

The 2026-10-16 preview.2 results and exact remaining gates are in [RELEASE_HARDENING.md](RELEASE_HARDENING.md). Its hardening-* evidence supersedes the historical outcome tables below: actual Linux engine race, protected router/helper, wall-clock native queue schedules and real amd64 systemd/Docker installer upgrade/rollback now ran. Additional 16-check live HTTPS acceptance passes native backup/schedules/isolated restore/final deletion/retained maintenance/export against real Wings/Restic (bootstrap inventory and broadcasting mocked). New dependency audits fail; no security pass is claimed. Arm64 runtime and full distributed/browser restore acceptance remain outstanding.

## Exact targets

Current admin UI evidence: evidence/admin-tabs-*.txt and admin-tabs-browser-*.json. Three native tabs share one sidebar resource. Actual Edge headless desktop and 390-pixel browser checks exercise native sidebar/tabs, saved metrics, structured details and password confirmation against a disposable SQLite fixture; application Vite hooks and node HTTP are omitted. This does not establish full Linux plugin installation or native restore browser acceptance. Node binaries are unchanged; real Restic/router/build results below remain prior-generation evidence, not fresh UI-session runs.

| Component | Pinned target | Demonstrated here |
|---|---|---|
| Panel | v1.0.0-rc.1, 46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00 | PHP8.5.11 native services/importer/SQLite/Livewire, node HTTP and asset processes mocked |
| Wings | v1.0.0-rc.1, 7b489da7b8196d0b4baef3e2db062c06d743e2f9 | Go1.27.2 Linux amd64/arm64 builds; protected-router tests cross-compiled, not run |
| Restic | 0.19.1, 6aa3a516ce654808a1f28f9fa21e9b7c8e6e90bf | Security-locked rebuilt runtime, real temporary local Windows repositories |
| Legacy audit | 4ce6c042086f8e359ce5910422e02823b500ec12 | Full tracked file/route inventory; no legacy runtime claim |
| Installer | Linux/systemd amd64/arm64 target | Git Bash filesystem/HTTP/service/helper fixtures; not real systemd |

compatibility/versions.json is authoritative for source contract hashes and dependency locks. Other Panel/Wings versions are unsupported. Tool binaries/caches and upstream checkouts are ignored developer state, not operator installation prerequisites.

## Current outcomes and commands

Run developer commands from the source root after preparing the exact tools. On this Windows workspace PHP and Go live under .tools/php/php.exe and .tools/go/bin/go.exe; Git Bash is C:/Program Files/Git/bin/bash.exe. Replace plain php/go below with those paths or add their directories to PATH.

| Command | Outcome / scope |
|---|---|
| php scripts/package-panel-plugin.php; php scripts/test-panel.php | **424 checks pass**. Native importer/reinstall/migrations, forms, create/status/download/restore dispatch, schedules/RBAC/lock/quota/retention, repair/keys/lifecycle plus actual rendered labels with both button styles, admin-only password-only force metadata/repository cleanup, offline and forged/lost-response retries, deleted-server inventory and preserved restore protection. SQLite/Livewire; node HTTP/assets mocked. Fixture process uses 512 MB for repeated page renders, independent of production PHP settings. |
| php scripts/test-panel-queue.php | **8 checks pass**. Fresh native CLI plugin discovery, stable job unserialize, real SQLite queue handler/completion/replay/retention/prune, automatic repair command registration. Node HTTP mocked. |
| RESTIC_TEST_BINARY=/absolute/rebuilt/restic go test -count=1 -v ./ibyte/restic/engine (inside wings-addon) | **30 top-level tests pass**, 280.608s. One top-level and two subcase Windows symlink privilege skips. Real local create/restore/metrics/forget/prune/key-upgrade/proof; complete nonempty force deletion despite missing mapping/marker, damaged config/password and stale locks; missing repo retirement, active work/path/identity refusal, preserved game/neighbor/key files and restore journals. |
| go test -mod=readonly -count=1 -v ./ibyte/cmd/node-tool (inside prepared pinned Wings tree) | **4 pass**. Real local HTTP/TLS fixtures for bearer/readiness/version, bad TLS and redirect refusal. |
| bash scripts/test-installer.sh | **17 pass**, mocked systemd/binaries/helper/downloads. Includes the public copied-folder entry point installing through the real inner script, repeat-safe install and rollback with no downloads or artifacts/hash flags. Startup/readiness failure rollback, modified binary/manifest and automatic pin checks remain covered. |
| bash scripts/test-bootstrap-installer.sh | **12 pass**. Literal arguments, tested GitHub downloads, version/tamper/refusal, copied folder discovery from another working directory without downloads, both architectures, missing payload and modified binary refusal even under verify-only. Actual generated local payload is verified too. |
| php scripts/test-installer-package.php | **6 pass**. Complete unpublished payload, byte-exact artifacts, deterministic repeated packaging, small configured GitHub mode, switching back, and unexpected-file preservation. Tiny fake binaries test layout only, not Linux execution. |
| php .upstream/panel/vendor/bin/pint panel-plugin/src panel-plugin/tests scripts | Style passes. Current UI PHP syntax/style and secret scan: evidence/admin-tabs-quality.txt and admin-tabs-secrets.txt. Node/Bash sources are unchanged in this revision. |
| php scripts/package-release.php; bash scripts/validate-release.sh | Builds the two-folder distribution/source/plugin/manifests. Validator checks exact public files, archive equality, new modules/tests, source exclusions, embedded pins and all six Linux ELF architectures. Current output: evidence/admin-tabs-release-validation.txt. |

Automation regressions explicitly cover key escrow before dispatch, older-repository synchronization, coalesced stats, cached customer/admin reads with no node calls, pending newer backup fallback, and verified snapshot success with an accounting warning instead of invented repository bytes. Engine storage tests verify each of two real backups carries fresh repository measurements.

Browser reproduction and its explicit limits are in scripts/fixtures/admin-browser/README.md. The native suite additionally covers independent tab authorization/action registration, both button preferences, missing tables on all tabs, safety flags preserved by basic host edits, wrong-host selection, immutability, unavailable/zero/deleted/generation-scoped metrics and rate-limited durable maintenance.

## Reproduce central Linux builds and CI

Use a disposable source checkout with Go1.27.2, PHP8.5.11, Composer2.10.3, Git, Bash and standard build tools:

```bash
bash scripts/fetch-upstream.sh
composer install --no-interaction --prefer-dist --no-scripts --working-dir=.upstream/panel
bash scripts/fetch-restic.sh
php scripts/package-panel-plugin.php
php scripts/test-panel.php
php scripts/test-panel-queue.php
bash scripts/test-installer.sh
RESTIC_TEST_BINARY="$PWD/.build/release-artifacts/restic-0.19.1-linux-amd64" bash wings-addon/scripts/build-wings-restic.sh
php scripts/package-release.php
bash scripts/test-bootstrap-installer.sh
bash scripts/validate-release.sh
```

The central Wings script applies checked router/dependency patches, overlays ibyte/ sources and runs real Linux engine/router/helper tests with the race detector before cross-building. That Linux CI path is configured, **not executed from this Windows session**. Locally the same pinned prepared source cross-built both daemon/helper architectures with GOOS=linux, CGO_ENABLED=0, -mod=readonly -trimpath -buildvcs=false and fixed version ldflags; go test -c compiled protected-route tests for both. Tracked upstream diff is only router/router.go (3 added lines including whitespace), go.mod and go.sum (dependency patch). Restic source/dependency build is unchanged this turn.

Run source dependency audits with bash scripts/audit-dependencies.sh and composer audit --locked --working-dir=.upstream/panel. Recorded previous scans remain applicable to unchanged dependency locks but are not represented as fresh audits. SECURITY.md explains unresolved Docker/OpenPGP matches and source/binary differences. CI publication intentionally fails on uncleared findings; this session did not publish a release.

## Required disposable acceptance before production

1. Install/update the plugin ZIP in a real browser, run the matching script twice on an actual Linux node and force new-daemon startup failure to verify rollback. Preserve original config/data/repository digests.
2. Through native Backups create two snapshots of a known world; verify automatic admin keys and measured dedup/compression, owner mapping, downloaded tar contents and byte-for-byte offline recovery.
3. Use real native hourly Schedules with a small quota; observe queue/cron execution, unlocked replacement, locks/all-protected refusal and daily/weekly policy/prune. Direct RunTaskJob tests do not prove timed cron/queue operation.
4. In an isolated fixture only, stop all writers, explicitly enable restore testing and exercise native selected-snapshot restore plus forced commit failure. Production restore requires upstream supported dispatch and complete in-flight write fencing first.
5. Repeat failures on MariaDB/Redis: competing create/delete/lock/transfer, network loss, full disk, killed workers/daemon and reconciliation. Validate S3 provisioning/lifecycle and multiple real nodes/architectures. Current sequential SQLite/local repository tests do not substitute.

Until these gates and security/API issues are resolved, keep preview enabled only on disposable servers. No deployment to the owner's production Panel/nodes was attempted.

Current force-cleanup evidence is in evidence/forced-cleanup-*.txt. Real native Livewire actions test password field validation, administrator-only placement, offline cleanup of locked/recent/stuck records, preserved restore protection, source inventory with no live Panel server, and lost/forged node responses with stable retry intent. Actual rendered labels are checked under both Pelican button_style preferences. This caught the gap in earlier visibility-only tests; it is still not a real browser/Alpine interaction test. The fixture process memory allowance was raised to 512 MB after repeated page renders exhausted the local 128 MB CLI default; production settings were not changed.

Key/lifecycle regressions execute actual native header Delete and inline Transfer confirmations with their original handlers, verify older lock refusal, two older snapshots forgotten before daemon removal, one retained mapping, source/destination identity, durable reservation blocking/retry, native transfer row commit before daemon notification and destination-only native success callback. Key-history tests cover encrypted generations, matched legacy escrow upgrade, password-protected root views and offline/deleted-server recovery. Real Restic verifies independent passwords, shared-profile/cross-server refusal, restart-safe key-add/finalize and old-wrapper retirement after a receipt. These do not prove real Linux daemon transport, browser rendering or distributed MariaDB transactions.

Earlier diagnostic and conservative-cleanup results remain in evidence/runtime-binding-*.txt and evidence/repository-controls-*.txt. They describe those revisions, not the current admin force policy. The supplied operator traces alone cannot identify a production process's installed code or a node's current state.

Both Linux daemon/helper architectures are rebuilt and protected-router tests cross-compiled with the updated single registration call. Windows real Restic force tests destroy a nonempty temporary repository despite missing marker/mapping, wrong key, damaged config and stale locks, preserve game/neighbor/key files, retire an already absent leaf and keep interrupted restore journals. Busy jobs, invalid node/UUID, location mismatch and protected-root overlap refuse removal. Three Windows privilege skips cover symlink cases; Linux runtime execution, remote endpoints and browser installation remain unverified.

The force-cleanup revision checked 63 PHP files, 13 Bash files and isolated Go formatting. This packaging update adds a ninth PHP script and freshly checks script syntax/style and Bash syntax, without runtime code changes. Package validation checks the force service, modal error adapter, regression fixtures, all copied payload bytes, standalone Wings ZIP and configured/local delivery layouts. Original restore/API/write-fence/security and distributed acceptance gates remain blocked.

Repository generation update: evidence/generations-*.txt records native Create after explicit deletion, native scheduling after manual local removal, generation-bound restore queue, retained admin keys with locations, transaction rollback and prepared-key retry, and handled offline/wrong-generation failures. Real Restic verifies fresh sibling repositories and random keys, preserved old key files/copy access, restart-safe preparation, late-old-delete isolation and byte-for-byte replacement restore. Latest initialized-state probe targeted rerun: two tests pass (32.564s). Both Linux binaries were rebuilt and router tests cross-compiled, not executed. 66 PHP files pass syntax; no real browser/Linux/systemd/MariaDB/S3/multinode acceptance is invented.

## Panel preview.5 server destruction acceptance (2026-10-18)

Commands: `php scripts/test-panel.php` (549 native SQLite/Livewire checks), `php scripts/test-panel-queue.php` (14 fresh-process native CLI/database-queue and timed schedule checks). Node responses in those PHP suites are mocked. Wrong passwords, unauthorized users, missing/S3 inventory, native deletion interruption, durable crash recovery, immutable generations, offline retry, multiple old-node repositories, preserved key/restore evidence and neighboring server isolation are covered. Evidence: `evidence/destruction-panel.txt` and `destruction-queue.txt`.

Real disposable Linux acceptance: `IBYTE_DISPOSABLE_LINUX=1 IBYTE_TEST_SERVER_DESTRUCTION_ONLY=1 bash scripts/acceptance/live-node.sh` in the dedicated `iByteResticAcceptance` WSL fixture. Nine checks pass against actual HTTPS Wings preview.2, Restic and the native database queue: create a locked snapshot, native force delete the server/game root, then remove the complete real repository and preserve encrypted key history and neighboring data. Only Panel bootstrap inventory and backup broadcasting are mocked. Evidence: `evidence/destruction-live-node.txt`. This exercises a stopped disposable server, not a live game, production node, remote multi-node runtime or arm64 runtime.

Source Gitleaks, 100 PHP syntax checks, Pint, six package-mode fixtures and complete release validation are recorded in `evidence/destruction-*.txt`. Node source/binaries are unchanged; earlier Go test results are historical. Original restore, dependency and distributed acceptance production gates remain open.
