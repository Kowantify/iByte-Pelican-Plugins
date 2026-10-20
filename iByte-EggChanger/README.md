# iByte Egg Changer

Resource-gated egg switching for Pelican Panel: administrators define packages
(minimum RAM, minimum disk, approved eggs); server owners see a simple eligible
list and run an explicit clean reinstall switch with a verified backup first.

Native Pelican plugin — no Blueprint, no panel core edits, no custom Wings.

## Install

1. Admin > Plugins > Import the ZIP `ibyte-egg-changer-0.1.0.zip`.
2. Install it (migrations run automatically; the panel requires a running queue
   worker — `pelican-queue.service` in the standard install).
3. Admin > **Egg Changer**: confirm Diagnostics shows `ready`, then create
   packages (name, RAM/disk minimums in MiB, approved eggs). Enable packages
   when you are done — disabled packages expose nothing.

## Customer flow

Server area > **Egg Changer** (owner only in this preview):

1. See current egg, allocated RAM/disk (Unlimited shown when the allocation is 0).
2. Pick from the flat eligible-egg list; preview the clean install scope.
3. Confirm once on the destructive acknowledgement screen.
4. Progress survives refresh; the operation is journal-backed.
   A verified native backup is created before anything is deleted.

## What it never does

- Never wipes or reinstalls without the owner's explicit confirmation and a
  **verified completed recovery backup** (unless the admin releases each).
- Never grants subusers egg selection (delegated switching is a later feature).
- Never edits panel core, runs manual SQL, or auto-imports eggs.
- Deleting a package deletes only the policy mapping — never servers or eggs.

## Update / Remove

Update by importing a newer ZIP over the same plugin id; packages and history
are preserved (migrations are additive). Removing the plugin keeps the history
tables by design.

## Status

**Production release** (`0.1.0`). Native plugin for Panel `^1.0.0-rc.1`
(tested against source `46fc1cf0…`, installed and running in production).
Verified: unit tests for eligibility, source contract checks against Panel
v1.0.0-rc.1 (`46fc1cf0…`) and Wings (`7b489da…`), php -l on every packaged
file, and a production install through the native importer. The operator
exercises real switches per-server; failures/reconciliation are journaled.