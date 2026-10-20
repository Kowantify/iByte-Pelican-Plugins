# Current artifacts: Panel preview.5 / Wings preview.2

2026-10-18. Unpublished staging candidate; not production ready. Panel-only update: unchanged Wings preview.2 binaries and handshakes. This update adds no migration or Panel core edit. Earlier preview.4 includes an additive alert receipt migration.

Operator delivery has exactly two folders: Panel Plugin and the self-contained Wings Installer. Checksums are verified automatically.

| Artifact | Bytes | SHA256 |
|---|---:|---|
| `release/Panel Plugin/ibyte-restic-pelican-plugin-v3.0.0-preview.5.zip` | 175694 | `d2e4608ad661eb2e885fdb6dbe74cca0275e8414efdb9f5521345a1fe2f539d1` |
| `.build/release-artifacts/ibyte-restic-install-v3.0.0-preview.5.zip` | 56408262 | `651e368376bd2f02118ac7ba5e05892931dcddcbc9e4f5a48ab333a654439491` |
| `.build/release-artifacts/ibyte-restic-pelican-v3.0.0-preview.5-source.zip` | 741087 | `95f869af0632eca2630898d4dcd7a007ff10ea7e17b5d2e717b9d5197845961a` |
| `.build/release-artifacts/ibyte-restic-wings-v3.0.0-preview.2.zip` | 56229617 | `78d6c24ff90929fa98a3dd6b20e3584d6b794afc9f175ab63e437514aab92a60` |

Validation: 549 native Panel SQLite/Livewire checks; 14 fresh-process native CLI/database queue/timed schedule checks; nine real disposable Linux HTTPS Wings/Restic server-plus-repository destruction checks. The PHP suites mock node replies; real Linux destruction mocks only Panel bootstrap inventory and backup broadcasting. Source Gitleaks, 100 PHP syntax checks, Pint, six package-mode fixtures and complete plugin/source/two-folder/manifests/six-ELF/installer verify-only validation pass. Evidence: docs/evidence/destruction-*.txt.

Update by importing preview.5 over the existing plugin ID, wait for installation success, then restart the actual queue. Compatible Wings preview.2 nodes need no reinstall. In the native admin Delete dialog, select retention (default) or permanent server-plus-local-repository destruction and enter the current Panel password only. Destruction skips final-backup/escrow preparation and snapshot locks; unavailable or refusing nodes remain pending until verified. Keys/owner history and restore evidence remain. Captured local inventory is required; S3 physical cleanup is unsupported.

Exact pins and open restore/dependency/distributed/arm64/production acceptance gates remain in compatibility/versions.json and RELEASE_HARDENING.md. Previous preview.4 artifacts are archived under .build/previous-releases/3.0.0-preview.4. Raw node assets and SHA256SUMS remain under .build/release-artifacts. This digest report is excluded from the source ZIP to avoid hash cycles. Nothing was deployed or published.
