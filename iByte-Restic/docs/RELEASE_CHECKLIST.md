# Release gates

**Status: staging preview only; production and publication blocked.** A successful compile or mock is not acceptance. Exact commits/contract hashes: compatibility/versions.json. Actual commands/outcomes: TESTING.md.

Implemented locally: native plugin import/update/additive repair, third schema, conditional form, RBAC/locks/quotas, native schedule task dispatch, durable queue completion, automatic encrypted key capture, automatic stored metrics, native retention/prune, unified admin overview, real temporary Restic create/download/restore/delete/prune engine tests, minimal upstream patch, two-folder packaging and verified installer mocks.

Before production:

- [ ] Supported Panel restore dispatch contract (UPSTREAM_API_PROPOSAL.md); current internal binding is source-gated preview code.
- [ ] Wings write fence covering new and in-flight HTTP/upload/pull/SFTP writers; current fixture restore cannot prove native quiescence.
- [x] Actual Ubuntu 24.04 WSL2 amd64/systemd/Docker install, preview upgrade, repeated install, failed startup automatic rollback, stock rollback and authenticated readiness, with mocked Panel inventory (hardening-systemd.txt).
- [ ] Arm64 Linux runtime/systemd acceptance; full game-container/multinode acceptance.
- [ ] Real browser plugin ZIP install/update and native create/lock/download/delete/restore UI acceptance.
- [ ] Full timed native cron/queue schedules and retention in a disposable Linux Panel/Wings environment, beyond direct RunTaskJob tests.
- [x] Wall-clock native p:schedule:process due selection, delayed serialized RunTaskJob, durable completion and no duplicate dispatch on Linux; node responses mocked (hardening-linux-queue.txt).
- [x] Actual single-node verified HTTPS Panel-to-Wings-to-Restic timed native backup, selected-snapshot isolated restore, final-backup/native deletion, retained maintenance and gzip recovery export (hardening-live-node.txt). Bootstrap inventory and completion broadcast mocked; no competing writers, running game, distributed failures or external cron claim.
- [ ] MariaDB/Redis concurrency, cross-system failures, full disk, process interruption and native restore rollback tests.
- [ ] S3-compatible provisioning/lifecycle and multi-node isolation/runtime acceptance; native latest-only retention/transfer callbacks are verified with mocks; real multi-node transfer runtime and repository relocation remain unverified/unsupported respectively.
- [ ] Resolve/review recorded upstream dependency advisories without suppressing audit failures; CI publication currently fails this gate.
- [ ] Publish a reviewed immutable tagged GitHub release with actual repository URL, raw bootstrap assets, SBOMs/checksums and trusted bootstrap distribution.

Legacy additions are prioritized separately in FEATURE_PARITY.md. Repository adoption/migration, unsafe unlock/repair, destructive rotation and automatic wiping remain excluded. No production access or deployment occurred.
