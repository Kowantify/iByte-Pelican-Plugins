# iByte Egg Changer — v2.0.0 (Pelican plugin)

This release simplifies the administrator and customer screens while **preserving the existing Egg Changer package gates, recommendations, local switching entitlements, API, database migrations, and backup-first switching code from v0.3.1**.

## Changes

- Theme-consistent Filament sections, inputs, badges, and buttons; bare white inner borders replaced by quiet, dark-mode-aware row separators.
- Customer server view: search eligible games, show the current egg first, render ten at a time, and progressively show ten more. Recommendations are collapsed under each game. Destructive preview remains explicit and is positioned above the catalog when opened. Empty operation history no longer occupies a large panel.
- Admin view: Servers / Game gates / Recommendations / Operations / Diagnostics workspaces. Server permissions remain searchable/paginated. Approved eggs within each gate are collapsed. The gate and recommendation forms open on Add/Edit instead of staying on screen. Recommendations can be filtered by game, with a maximum of fifty results per view.
- No new migrations, API routes, permissions, tokens, or Paymenter changes.

## Install

Update the **existing** `ibyte-egg-changer` plugin to this build. Do not uninstall it or delete tables. Back up the plugin directory and the Pelican database first. After updating, verify the plugin version and clear caches on the Pelican VPS:

```bash
cd /var/www/pelican
php artisan optimize:clear
php artisan queue:restart
```

No database migration is introduced in v2.0.0; **older installations still need all prior migrations**, including the entitlement and recommendation tables.

## Test before enabling broadly

1. In Pelican Admin → Egg Changer, visit each of the five admin tabs; edit and cancel an existing gate and recommendation, then verify the data is unchanged.
2. Search server permissions by UUID/name, toggle a disposable server, refresh, and confirm it persisted. Non-root admins must remain blocked.
3. On an entitled test server, search for a game, open use-case recommendations, show more results, and open/cancel a clean-install preview.
4. On a disposable test server only, verify the actual queued switch/backup/reinstall workflow separately before using production worlds.
5. Confirm switching still works when Paymenter is inaccessible.

## Rollback

Restore the backed-up v0.3.1 plugin files, clear caches and restart queue workers. No database rollback is required or recommended for this **UI-only** update.

## Important existing behavior

Administrator entitlement changes use last-write-wins: a later Paymenter entitlement synchronization can overwrite an administrator's grant/revocation. This behavior is **unchanged**. Switching uses Pelican's local entitlement database; Paymenter availability is not required at switch time.

## Test scope

PHP syntax and automated source/structure checks run locally. This build has **not** been exercised against the live Pelican/Filament instance and should be tested on a disposable server before production rollout.
