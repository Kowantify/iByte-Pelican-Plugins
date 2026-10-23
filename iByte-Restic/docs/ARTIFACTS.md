# Current Panel plugin package: 2.0.2

2026-10-23. Adds process-specific service-binding diagnostics after the operator observed a fresh CLI reporting ready while the queue worker rejected the backup service binding. Wings 3.0.0 is unchanged. The binding guard is not bypassed, and no migration, dependency, key or repository changes are included.

| Artifact | Bytes | SHA256 |
|---|---:|---|
| `release/Panel Plugin/ibyte-restic-v2.0.2.zip` | 164880 | `659b95640452c7d67853457a775873188fd5427022a08cfc52a572fcc7ac30b5` |

Import over the existing plugin ID; do not uninstall. Wait for install completion and restart the queue. The new error identifies expected/resolved class, resolved source file, process ID and SAPI if a mismatch recurs. The operator reports backups began working again after pushing this update; the exact binding change and status of the original pending backup were not reported. No production test was run by this agent.

The queue logs provided show `CaptureRecovery` fails at `RecoveryVault::capture()` because the queue process resolves a non-Restic `InitiateBackupService`. Panel-side source search found no other plugin bind for that service; iByte Egg Changer only resolves it. The conflicting runtime implementation remains unidentified. Previous Panel packages are archived under `.build/previous-releases/2.0.0` and `.build/previous-releases/2.0.1`.
