# Rollback

For the node, keep the copied Wings Installer folder intact and run `sudo bash install.sh --allow-preview --node-id NUMBER --rollback` from it. The adjacent payload is discovered automatically without --artifacts or GitHub setup. For a published download-mode script, retain its original --repository override if one was needed. The verified inner installer restores its recorded prior Wings executable and checks service readiness. Repositories, server data, node credentials and Panel records are retained. Unknown/custom or unhealthy states fail clearly. Real systemd acceptance is still pending; local tests mock services.

For Panel code, import the retained earlier compatible plugin ZIP and restart the actual queue worker. Do not uninstall or roll back/drop plugin tables; newer additive schema and queued payloads may require the matching newer code. Preserve protected database/APP_KEY backups, inspect in-flight operations and establish compatibility before any database restore. See UPGRADE.md and TROUBLESHOOTING.md.

Automatic node rollback handles failed new-daemon readiness after deployment. It cannot make a forgotten/pruned snapshot reappear or prove an interrupted restore safe. Unknown node work and restore journals require explicit operator reconciliation, never blanket unlock/reset.
