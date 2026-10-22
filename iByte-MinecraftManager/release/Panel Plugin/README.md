# iByte Minecraft Manager 2.0.0

Import this ZIP through Pelican Admin > Plugins and install/update it. No Wings changes are needed.

Take a backup before installing. The reminder is advisory. Failed/cancelled installs keep partial files/startup and release their slot; no automatic rollback is performed.

Downloads and extraction run on Wings. The Panel reads small manifest/index JSON and filesystem metadata, with completion/expected-size checks rather than full-payload checksum verification. Published hashes remain expected metadata. Temporary operation folders are cleaned afterward.

This package's version is 2.0.0. Packaging does not assert runtime or browser acceptance. Already-running workers retain loaded classes until refreshed.
