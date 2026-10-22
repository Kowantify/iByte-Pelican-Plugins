# Progress — 2026-10-20 (0.2.0 final)

Constraint pass per operator review: the plugin ships through Pelican's native plugin system only (manifest, providers, Filament page, scheduled command, route — no core edits); it exposes exactly one endpoint, `/ibyte-prometheus-exporter/metrics`, for the operator's own Prometheus to scrape; and the admin page asks for exactly one thing — a read-only scrape key (create/rotate/revoke). Creating or rotating the key is what enables collection; revoking stops serving snapshots and the collector goes idle. Removed the enable toggle, interval/timeout/concurrency inputs and all key-adjacent knobs: cadence is a fixed five-second schedule, freshness grace is 15 seconds, transport bounds are code-level constants.

Final cleanup pass (same day): audited every file for dead code. Removed the unverifiable `ibyte_pelican_exporter_key_active` metric (a scrape handler that 401s without a key could never emit 0), the fake `consecutive_failures => 99` sentinel (now real per-node accumulation), and duplicated HELP/TYPE emission (extracted `Metrics::help()`). Fixed a label-render bug the live endpoint run exposed (trailing comma inside per-server labels before `}`) — caught immediately by rendering the real endpoint and re-validating with `promtool check metrics` (exit 0). Fixed the curl_multi loop in `WingsClient` to the canonical CALL_MULTI_PERFORM/running-drain pattern; added a test-only DNS override (`IBYTE_PROMETHEUS_RESOLVE`, feeds CURLOPT_RESOLVE) so the local transport fixture can point `fixture.test` at a loopback HTTPS server — production never sets it. Deleted 35 one-off debug/probe scripts and both obsolete preview ZIPs from the release.

Full sweep re-verified after cleanup: `tests/normalizer.php` 25/25; `tests/panel.php check` 9/9; `tests/panel.php collect` 14/14 (native import/enabled, 403 anonymous/unauthorized, 401 wrong-key, 200 authenticated render, `server_info` name/owner labels, backup totals, duplicate-collector lock-safe, HTTP nodes skipped); `tests/transport.py` 2/2 real-socket cases (healthy TLS, 2 MiB limit, redirect rejection, deadline, 401, TLS-name mismatch, changed host, exactly five GETs); live endpoint hit over real HTTPS parsed clean by `promtool check metrics`; repeat-safe `setup.sh` re-run verified. Packaged `release/Panel Plugin/ibyte-prometheus-exporter-0.2.0.zip` (SHA-256 `BFEB3C5A4C06515E94C32629674DBE89E64BDA11937721062E19A4E7353C40DF`) and installed that exact artifact through the native importer.

# Progress — 2026-10-20 (0.2.0)

Simplified the plugin to the operator's actual ask: one token-protected `/metrics` endpoint exporting **all** nodes and game servers with CPU, memory, cached game-data disk, runtime state, per-node non-failed backup count/bytes and collection health. Removed per-node selection, DNS address pinning/approval storage, timeout/concurrency UI knobs, Grafana URL input and the setup-tab scrape-config download. Settings now persist `panel_id` and only regenerate `generation` when contents change, so stable labels survive routine saves.

[2026-10-20 full 0.2.0 verification details appended below in DECISIONS.md and versions.json]

Fixture verification (WSL2 distro `iBytePrometheusAcceptance`, Panel v1.0.0-rc.1 `46fc1cf0…`, Wings v1.0.0-rc.1 `7b489da…`, PHP 8.5.11, Laravel 13.35.0):
- `tests/normalizer.php`: 25/25 unit checks including name/owner carry-through, injected-quote/verbatim storage, stop-state zeros with nonzero disk, unlimited allocations, foreign-UUID rejection, HTTP/redirect node skipping without stalling.
- `tests/panel.php check`: native install path intact; anonymous and unauthorized admin actions rejected (403); wrong-token metrics rejected (401); authenticated render 200 with `server_info`, backup and health series; duplicate collector safely exits under the shared lock.
- `tests/panel.php collect`: real Wings `GET /api/servers` collection succeeded on two HTTPS fixture nodes (~4 ms each), HTTP factory-stray nodes skipped (2), authoritative Panel inventory enforced, backups counted per node, exposition assertions passed. Owner bug found and fixed: eager load needs `owner_id` selected or Laravel builds `where 0 = 1` against `users` and the owner renders blank.
- Rendered exposition passed `promtool check metrics` (exit 0); live stack passed `promtool check config`. Backup families renamed `_count`→`_stored` after promtool flagged the reserved suffix.
- Live Prometheus returned samples with `name="Brennan"`, `owner="gene98_uaqpsvzuse"`, CPU ~1.0 core, memory ~17 MB, disk 1 MiB, correct limit/unlimited series.
- Repeat-safe `setup.sh` re-ran without overwriting config/secrets/volumes.
- Packaged and re-installed `release/Panel Plugin/ibyte-prometheus-exporter-0.2.0.zip`.
- Open for operator: Grafana browser walkthrough (fixture firewall isolates some container-to-container flows); full `AGENTS.md` acceptance gates (restart persistence, multi-host cache, TLS matrix).

# Progress — 2026-10-19

Implemented a preview native plugin with one root-admin Filament page, selected-node settings, one-time dedicated scrape token rotation, a Laravel sub-minute collector, whitelisted Wings batch normalization, cached authenticated metrics, and a Grafana dashboard. Added an optional Compose stack and an existing-stack scrape example.

Source review: `git -C ../iByte-Restic/.upstream/panel rev-parse HEAD` returned `46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00`; Wings returned `7b489da7b8196d0b4baef3e2db062c06d743e2f9`. Official `git ls-remote --tags` for both repos confirmed these are the latest published tags visible on 2026-10-19. Docker Hub tag API returned the pinned image digests in `compatibility/versions.json`. See `REFERENCES.md` for source semantics.

Static checks: `node .validation/check.js` with `php-parser@3.7.0` parsed every plugin PHP file successfully; the temporary validation directory was removed afterward. PowerShell `ConvertFrom-Json` parsed the manifest and dashboard. `tar -tf` confirmed the import ZIP has one `ibyte-prometheus-exporter/` root and expected files. Reading Laravel 13.35.0 source confirmed `Pool::as()` keys responses and `PendingRequest::pool()` returns response/Throwable values; scheduler source has `everyFiveSeconds`, `withoutOverlapping` and `onOneServer`. These are source checks, not runtime evidence.

Prometheus validation: downloaded official `prometheus-3.5.0.windows-amd64.zip` and `sha256sums.txt`; SHA-256 matched `b3c2607d1e80a277735fd3cde86432eb1b6843897cb82fb04fbf4c7ffb1d1c36`. `promtool check config --syntax-only release/Monitoring Setup/prometheus.example.yml` passed. A copy with only the unavailable container token-file path replaced by a disposable local file passed full `promtool check config`. Extracted all nine Grafana target expressions into temporary recording rules; `promtool check rules` passed all nine. Temporary downloaded tools/configs were removed. This checks syntax, not Grafana rendering or live scraping.

Current test status: native ZIP import/install/update/disable/uninstall, PHP runtime lint, live scheduler timing, disposable Linux Panel/Wings, real running/stopped servers, two-node transfer, Prometheus restarts/history, Grafana browser import, and performance/security acceptance are **not yet verified**. Windows environment has no `php` or `docker` command available; WSL enumeration only found the sibling Restic acceptance environment, which this project must not reuse. No production access was used. Do not call this production ready.

Outstanding security/reliability issues before production: DNS rebinding control, runtime validation of bounded Wings streaming and scheduler behavior, and full acceptance gates from `AGENTS.md`. The collector enforces a 2 MiB response bound while streaming, but this has not been exercised against a real Wings node.

Packaged `release/Panel Plugin/ibyte-prometheus-exporter-0.1.0-preview.1.zip` with SHA-256 `081B3E2EC12D81A846F4EDE77AD33EFA9F4212EA9326E67366AA48266ED6D619`. Only `Panel Plugin` and `Monitoring Setup` exist under `release/`.
## 2.0.0 package — 2026-10-22

- Standardized the manifest version, archive name, and compatibility checksum to 2.0.0.
- Rebuilt `release/Panel Plugin/ibyte-prometheus-exporter-v2.0.0.zip`.
- Package metadata was checked. Panel import and live scrape acceptance were not rerun for this package.
