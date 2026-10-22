# iByte Egg Changer — release

## 2.0.0 (2026-10-22)

Version 2.0.0 package. Import `ibyte-egg-changer-v2.0.0.zip` through
Admin > Plugins > Import and press Install.

- Native plugin for Panel `^1.0.0-rc.1` (tested against source `46fc1cf0…`).
- Admin: one **Egg Changer** entry — Packages / Operations / Diagnostics.
- Client: one **Egg Changer** page in the server area, owner-only.
- Queue-based switch job: eligibility re-check → stopped-state proof → verified
  native recovery backup → scoped root cleanup → native `EggChangerService`
  egg/image/startup/variable swap → daemon sync → native reinstall dispatch →
  completion poll (`completed` / `failed` / `needs_reconciliation`).
- SHA-256 of the ZIP is recorded in `compatibility/versions.json` after packaging.

See the project `README.md` for the customer flow, and keep
The archive uses `ibyte-egg-changer-v<version>.zip`; its SHA-256 is recorded in `compatibility/versions.json` after packaging.
