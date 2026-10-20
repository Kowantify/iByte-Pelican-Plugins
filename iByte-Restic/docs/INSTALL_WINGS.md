# Install or update one Wings node

Download `ibyte-restic-wings-v3.0.0-preview.2.zip` and extract it, or use `release/Wings Installer` from the full package. Copy the **entire Wings Installer folder** to the node. This unpublished package is self-contained:

```text
Wings Installer/
  install.sh
  README.md
  payload/       (matching Wings, Restic, helper, installer and checksums)
```

Open a terminal in that folder and run:

```bash
sudo bash install.sh --allow-preview --node-id NUMBER
```

Replace NUMBER with this node's ID from Panel Admin > Nodes. Run on the Wings node, not a separate Panel machine. No GitHub repository, internet download, compiler, manual hash entry or --artifacts argument is needed. Keep payload next to install.sh; paths containing spaces and running from another working directory are supported. Both Linux amd64 and arm64 binaries are included; the script selects automatically.

The script verifies its inner installer, manifest and selected binaries before execution. The inner installer checks existing Pelican Wings/version/systemd/config, preserves configuration and game/repository/key data, backs up the current daemon, installs atomically, verifies authenticated addon health and rolls back failed startup. Existing private profiles are preserved; the standard local install provisions profile default. Node ID is required rather than guessed. An unknown/custom binary or unsupported version fails before replacement.

Verify files without changing the node: `bash install.sh --verify-only`. Roll back using the same copied folder:

```bash
sudo bash install.sh --allow-preview --node-id NUMBER --rollback
```

Run one node at a time without active addon work. Supported build targets are official Pelican Wings v1.0.0-rc.1 on Linux/systemd amd64/arm64. This remains a staging preview. Real Ubuntu 24.04 WSL2 amd64/systemd/Docker installation, preview.1-to-preview.2 upgrade, repeat installation, failed-start automatic rollback and stock rollback passed with a mocked offline-server Panel inventory. Arm64 is built and inspected but has no runtime acceptance. Full distributed and production restore gates remain blocked; see RELEASE_HARDENING.md.

When publishing, set GITHUB_REPOSITORY=OWNER/REPO during packaging. That produces the smaller install.sh/README folder and downloads the exact tested assets over HTTPS with automatic verification. The --repository override is available after a real release exists. No GitHub release has been published from this checkout. No remote download-and-execute pipe or encoded executable payload is used. Advanced --artifacts/--config/--service/--binary/--profile/--state/--repository-root options remain available; normal unpublished installs need none of them.

Defaults: service wings, native config /etc/pelican/config.yml, addon config /etc/pelican/ibyte-restic.json, repository base /var/lib/ibyte-restic/repositories, installer state /var/lib/ibyte-restic-installer. Never discard node private state/profile files to upgrade.

After installation, use the matching Panel ZIP and link the node explicitly to its Restic host with profile default. New keys are escrowed before dispatch. The screenshot's recovery-escrow error means key preparation/export or validation failed before a backup was dispatched; the screenshot alone cannot identify its exact cause. This package contains the matching node implementation. If the error persists after a verified update, inspect protected node logs and approved profile configuration; do not disable escrow, regenerate keys or wipe a repository.

The bundled node also supports password-confirmed local whole-repository deletion, including retained deleted/transferred sources. It advertises force_repository_delete=local-v1. Installation never invokes that operation. Physical S3 removal remains unsupported; see RECOVERY.md.

This build also advertises repository_generations=local-v1. After deletion, the next native manual or scheduled backup can create a fresh numbered local repository and random key on the same backup host. The old leaf must be proven absent; old keys/history stay available and late old jobs remain scoped to the old location. Install this Wings update first, then import the matching Panel ZIP and restart the actual queue worker. No manual key reset is needed. Existing paths, offline nodes and unresolved restores are never interpreted as safe-to-delete data.
