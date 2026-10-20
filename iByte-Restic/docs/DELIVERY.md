# Delivery map

Operators use only `release/Panel Plugin` and `release/Wings Installer`. See README.md and INSTALL_PANEL.md/INSTALL_WINGS.md for current instructions.

Maintainers generate `.build/release-artifacts/ibyte-restic-install-v3.0.0-preview.5.zip` containing those same two folders. The standalone `ibyte-restic-wings-v3.0.0-preview.2.zip` contains only Wings Installer for easy transfer. Without GITHUB_REPOSITORY, Wings Installer includes install.sh, README.md and payload/ with six binaries, the inner installer and manifest. Copy that whole folder and run the script; no --artifacts or GitHub setup is needed. Configured publication produces the smaller download-mode folder instead. Raw developer binaries/source ZIP/manifests remain under .build/release-artifacts; ARTIFACTS.md records names/digests. GitHub CI publishes the standalone Wings ZIP and raw assets. Nothing has been published from this checkout.

Build/test commands and actual outcomes: TESTING.md. Production acceptance: RELEASE_CHECKLIST.md. Historical reports are under archive/ and are not installation instructions.
