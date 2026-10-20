# Disposable native administration browser fixture

Never deploy this fixture. It authenticates a dummy root user only on the PHP development server and loopback interface, uses `.build/browser-admin.sqlite`, refuses stray HTTP calls and renders the exact pinned Panel's native layout, sidebar, tabs, tables, forms and Livewire assets. Application Vite passkey/clipboard hooks are omitted because this checkout has no application asset build. This is bounded browser UI evidence, not an installer, node, production frontend or restore acceptance test.

From the project root:

```sh
php -S 127.0.0.1:8099 scripts/fixtures/admin-browser/router.php
```

Open `http://127.0.0.1:8099/admin/restic-backups` in an isolated browser profile. Filament's published assets must exist in the pinned checkout. No daemon is connected. The three dummy repositories cover measured, unmeasured and retained-source states. Keys use fake credentials. Do not run destructive actions against anything outside this fixture.

For Windows automation, launch Edge headless with a disposable workspace profile, debug address `127.0.0.1`, debug port `9228` and that URL. Target the fixture explicitly so browser internal tabs are excluded:

```powershell
./scripts/fixtures/backup-host-browser/evaluate.ps1 -ExpressionFile scripts/fixtures/admin-browser/inspect.js -TargetUrlPrefix http://127.0.0.1:8099
./scripts/fixtures/backup-host-browser/evaluate.ps1 -ExpressionFile scripts/fixtures/admin-browser/inspect.js -TargetUrlPrefix http://127.0.0.1:8099 -Width 390 -Height 844
./scripts/fixtures/admin-browser/capture.ps1 -OutputPath .build/admin-preview.png
```

Click each tab and repeat inspection. On Administrative, run `modals.js` to check the labelled More menu and password gate. It leaves the password confirmation open; cancel it before navigating. The scripts throw on protocol/JavaScript assertion failures. Evidence is successful only when the returned value lists passed checks and has no protocol or exception details. Responsive emulation remains active only within its debugger session; use the width option on each inspection/capture. Mobile uses native compact navigation and internally scrolling tables.

The native SQLite/Livewire suite additionally exercises incorrect passwords, permissions lost between requests, missing tables, force cleanup, metrics generations and host configuration writes. Stop the development server and only the dedicated browser process when finished. Database/profile/screenshots are ignored disposable build state.
