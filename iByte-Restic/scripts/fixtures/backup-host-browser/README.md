# Disposable native host-form browser fixture

Never copy this fixture to an operator Panel. It automatically authenticates a dummy root admin against a separate `.build/browser-form.sqlite` database. The router refuses non-loopback clients and non-development-server execution. It uses only the project's pinned `.upstream/panel` checkout, locked dependencies, and source plugin. Install that checkout's Filament assets first if its public assets are absent. This fixture does not connect a daemon or install plugins through a browser.

From the project root with the pinned PHP/dependencies available:

```sh
php -S 127.0.0.1:8098 scripts/fixtures/backup-host-browser/router.php
```

Open `http://127.0.0.1:8098/form-fixture` in a fresh browser tab. Select Restic. Backend/profile should initialize to local/default and both safety settings should remain off without editable toggles. Change backend to S3 and back to local, then submit the native header Create action. No missing-property entanglement errors should occur; the resulting `backup_hosts.configuration` in the fixture DB must preserve local/default/false/false. A redirect after creation enters the normal native page and may need the disposable checkout's complete frontend assets; that is outside this bounded form fixture. Stop the PHP development server when finished.

The Windows automation used Edge headless with an isolated `.build/edge-form-profile` and `--remote-debugging-address=127.0.0.1 --remote-debugging-port=9228`. With a fresh fixture tab fully loaded, run:

```powershell
./scripts/fixtures/backup-host-browser/evaluate.ps1 -ExpressionFile scripts/fixtures/backup-host-browser/submit.js
```

The evaluator attaches to the first page target on that dedicated debug port. It must return a `result.result.value` containing passed checks and empty errors; an `exceptionDetails`/protocol error is a failed or incomplete attempt, not a pass. The submit action is asynchronous, so independently confirm persistence in the fixture SQLite DB. Existing `.build` files are disposable test state, not production repositories. The regular `php scripts/test-panel.php` suite additionally covers real native edit/re-import, invalid values and saved configuration preservation without a browser.

Advanced preview/restore controls now live in Restic Backups > Administrative and are tested in the native suite and separate admin-browser fixture.
