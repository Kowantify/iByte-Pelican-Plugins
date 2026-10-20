# iByte Egg Changer — release

## 0.1.0 (2026-10-20)

Production release. Import `ibyte-egg-changer-0.1.0.zip` through
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
`compatibility/versions.json` updated with every package change.