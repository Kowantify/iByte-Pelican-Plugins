<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
$panelRelease = $pins['panel_release'] ?? $pins['release'];
$panelZipName = 'ibyte-restic-pelican-plugin-v'.$panelRelease.'.zip';
$release = $root.'/.build/release-artifacts';
$folders = array_values(array_diff(scandir($root.'/release'), ['.', '..']));
sort($folders);
$nodeFiles = [];
foreach (['amd64', 'arm64'] as $arch) {
    foreach (['ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-', 'ibyte-node-tool-v3.0.0-preview.2-linux-', 'restic-0.19.1-linux-'] as $prefix) {
        $nodeFiles[] = $prefix.$arch;
    }
}
$bootstrap = file_get_contents($root.'/release/Wings Installer/install.sh');
if (! preg_match("/^URL='([^']*)'$/m", $bootstrap, $urlMatch)) {
    throw new RuntimeException('Installer publication mode is missing');
}
$bundled = $urlMatch[1] === '';
$wingsEntries = $bundled ? ['README.md', 'install.sh', 'payload'] : ['README.md', 'install.sh'];
if ($folders !== ['Panel Plugin', 'Wings Installer']
    || array_values(array_diff(scandir($root.'/release/Panel Plugin'), ['.', '..'])) !== ['README.md', $panelZipName]
    || array_values(array_diff(scandir($root.'/release/Wings Installer'), ['.', '..'])) !== $wingsEntries) {
    throw new RuntimeException('Operator release must contain exactly the two requested folders/files');
}
if (! hash_equals(hash_file('sha256', $release.'/'.$panelZipName), hash_file('sha256', $root.'/release/Panel Plugin/'.$panelZipName))) {
    throw new RuntimeException('Public Panel ZIP differs from verified archive');
}
$distribution = new ZipArchive;
$deliveryFiles = ['Panel Plugin/'.$panelZipName, 'Panel Plugin/README.md', 'Wings Installer/install.sh', 'Wings Installer/README.md'];
$payloadFiles = array_merge(['NODE-SHA256SUMS', 'install-wings-restic.sh'], $nodeFiles);
sort($payloadFiles);
if ($bundled) {
    if (array_values(array_diff(scandir($root.'/release/Wings Installer/payload'), ['.', '..'])) !== $payloadFiles) {
        throw new RuntimeException('Local Wings payload is incomplete or contains unexpected files');
    }
    foreach ($payloadFiles as $file) {
        $deliveryFiles[] = 'Wings Installer/payload/'.$file;
        if (! hash_equals(hash_file('sha256', $release.'/'.$file), hash_file('sha256', $root.'/release/Wings Installer/payload/'.$file))) {
            throw new RuntimeException('Bundled node file differs from release artifact: '.$file);
        }
    }
}
if ($distribution->open($release.'/ibyte-restic-install-v'.$panelRelease.'.zip') !== true || $distribution->numFiles !== count($deliveryFiles)) {
    throw new RuntimeException('Two-folder distribution missing or ambiguous');
}
foreach ($deliveryFiles as $file) {
    if (! hash_equals(hash_file('sha256', $root.'/release/'.$file), hash('sha256', $distribution->getFromName($file)))) {
        throw new RuntimeException('Public delivery differs from verified distribution: '.$file);
    }
}
$distribution->close();
$wingsZip = new ZipArchive;
$wingsFiles = array_values(array_filter($deliveryFiles, fn ($file) => str_starts_with($file, 'Wings Installer/')));
if ($wingsZip->open($release.'/ibyte-restic-wings-v3.0.0-preview.2.zip') !== true || $wingsZip->numFiles !== count($wingsFiles)) {
    throw new RuntimeException('Standalone Wings ZIP is missing or incomplete');
}
foreach ($wingsFiles as $file) {
    if (! hash_equals(hash_file('sha256', $root.'/release/'.$file), hash('sha256', $wingsZip->getFromName($file)))) {
        throw new RuntimeException('Standalone Wings ZIP differs from copied folder: '.$file);
    }
}
$wingsZip->close();
$nodeManifest = file_get_contents($release.'/NODE-SHA256SUMS');
$installer = file_get_contents($release.'/install-wings-restic.sh');
if (! str_contains($installer, "PINNED_NODE_MANIFEST_SHA256='".hash('sha256', $nodeManifest)."'")
    || str_contains($installer, '__IBYTE_') || substr_count(trim($nodeManifest), "\n") !== 5) {
    throw new RuntimeException('Installer release pin missing or wrong node manifest');
}
foreach (explode("\n", trim($nodeManifest)) as $line) {
    if (! preg_match('/^([a-f0-9]{64})  ([a-zA-Z0-9_.-]+)$/D', $line, $parts)
        || ! hash_equals($parts[1], hash_file('sha256', $release.'/'.$parts[2]))) {
        throw new RuntimeException('Node manifest does not match its artifacts');
    }
}
$zip = new ZipArchive;
if ($zip->open($release.'/'.$panelZipName) !== true) {
    throw new RuntimeException('Plugin ZIP unavailable');
}
foreach (['ResticPage', 'ListRecoveryRepositories', 'RepositoryStatistics', 'RepositoryAdministration', 'RepositoryColumns', 'RepositoryDetails', 'CredentialActions', 'HostActions'] as $module) {
    $entry = 'ibyte-restic/src/Admin/'.$module.'.php';
    if ($zip->getFromName($entry) !== str_replace("\r\n", "\n", file_get_contents($root.'/panel-plugin/src/Admin/'.$module.'.php'))) {
        throw new RuntimeException('Admin UI module is missing or stale: '.$module);
    }
}
if ($zip->locateName('ibyte-restic/tests/admin-navigation.php') === false) {
    throw new RuntimeException('Admin tab regression tests are missing');
}
foreach (['src/Support/RuntimeDiagnostics.php', 'src/Console/DiagnoseRuntime.php', 'tests/runtime-diagnostics.php', 'src/Recovery/RepositoryRemoval.php', 'src/Admin/RepositoryActions.php', 'tests/repository-controls.php', 'src/Recovery/ForcedRepositoryRemoval.php', 'src/Admin/ActionErrors.php', 'tests/forced-cleanup.php', 'src/Recovery/RepositoryGenerations.php', 'tests/repository-generations.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing runtime diagnostic entry: '.$entry);
    }
}
$manifest = json_decode($zip->getFromName('ibyte-restic/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['id'] !== 'ibyte-restic' || $manifest['version'] !== $panelRelease || isset($manifest['meta'])) {
    throw new RuntimeException('Invalid public plugin metadata');
}
foreach (['src/ResticPlugin.php', 'src/Providers/ResticProvider.php', 'src/Admin/HostForm.php', 'src/Integration/BridgeRepository.php', 'src/Storage/StorageReport.php', 'src/Integration/LifecycleGuard.php', 'src/Integration/GuardedServerDeletion.php', 'src/Recovery/RecoveryVault.php', 'src/Admin/RecoveryResource.php', 'src/Recovery/RecoveryRevealController.php', 'src/Recovery/RetainedDeletion.php', 'src/Backups/Retention.php', 'src/Admin/RetentionActions.php', 'src/Integration/RetainingBackupService.php', 'src/Jobs/ApplyRetention.php', 'database/migrations/2026_10_14_000001_create_ibyte_restic_operations.php', 'database/migrations/2026_10_15_000001_add_ibyte_restic_storage_metrics.php', 'database/migrations/2026_10_15_000002_create_ibyte_restic_recovery_inventory.php', 'database/migrations/2026_10_15_000003_create_ibyte_restic_retention.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing plugin entry: '.$entry);
    }
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (! str_starts_with($name, 'ibyte-restic/') || str_contains($name, '..') || str_contains($name, 'proposals/') || str_contains($name, 'patches/')) {
        throw new RuntimeException('Unexpected plugin ZIP path');
    }
}
$embedded = json_decode($zip->getFromName('ibyte-restic/compatibility.json'), true, flags: JSON_THROW_ON_ERROR);
$pins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
if ($embedded !== $pins) {
    throw new RuntimeException('Embedded compatibility pins differ');
}
foreach (['src/Recovery/ServerDestruction.php', 'src/Jobs/DestroyServerRepositories.php', 'tests/server-destruction.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing destructive server lifecycle input: '.$entry);
    }
}
if (isset($pins['panel_files']['composer.lock']) || count($pins['panel_runtime_packages'] ?? []) !== 168) {
    throw new RuntimeException('Runtime package inventory must replace the whole-lock hash');
}
foreach (['src/Support/DependencyContract.php', 'tests/compatibility.php', 'src/Support/NodeDiagnostics.php', 'src/Support/OperationDiagnostics.php', 'src/Console/DiagnoseNode.php', 'tests/alerts-diagnostics.php', 'database/migrations/2026_10_16_000001_add_ibyte_restic_alert_delivery.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing compatibility hotfix input: '.$entry);
    }
}
foreach (['src/Support/Installation.php', 'src/Support/Maintenance.php', 'src/Integration/ModelObservers.php', 'src/Console/RepairInstallation.php', 'src/Backups/RecordRemoval.php', 'src/Admin/StaleBackupActions.php', 'src/Admin/ServerLifecycleActions.php', 'database/migrations/2026_10_15_000004_create_ibyte_restic_key_history.php', 'tests/server-keys.php', 'tests/installation.php', 'tests/automation.php', 'tests/stale-records.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing installation repair entry: '.$entry);
    }
}
$zip->close();
$source = new ZipArchive;
if ($source->open($release.'/ibyte-restic-pelican-v'.$panelRelease.'-source.zip') !== true) {
    throw new RuntimeException('Source ZIP unavailable');
}
for ($i = 0; $i < $source->numFiles; $i++) {
    if (preg_match('#/(?:\.tools|\.upstream|\.build|\.git)/#', $source->getNameIndex($i))) {
        throw new RuntimeException('Private/generated cache in source ZIP');
    }
}
foreach (['restic-runtime/patches/v0.19.1-security-deps.patch', 'wings-addon/patches/pelican-wings-v1.0.0-rc.1.patch', 'compatibility/proposals/panel-v1.0.0-rc.1-restore-api.patch', 'docs/DELIVERY.md', 'docs/RETENTION.md', 'docs/TESTING.md', 'scripts/bootstrap-wings.sh', 'scripts/test-bootstrap-installer.sh', 'scripts/test-installer-package.php', 'panel-plugin/tests/retention.php', 'panel-plugin/tests/automation.php', 'wings-addon/ibyte/restic/engine/prune_test.go'] as $path) {
    if ($source->locateName('ibyte-restic/'.$path) === false) {
        throw new RuntimeException('Source ZIP missing required review/build input');
    }
}
$source->close();
$source = new ZipArchive;
$source->open($release.'/ibyte-restic-pelican-v'.$panelRelease.'-source.zip');
foreach (['docs/RELEASE_HARDENING.md', 'scripts/set-release.php', 'scripts/acceptance/live-node.php', 'scripts/acceptance/live-node.sh', 'panel-plugin/tests/hardening.php', 'panel-plugin/tests/final-backup.php', 'panel-plugin/tests/repository-export.php', 'wings-addon/ibyte/restic/engine/export_retained_test.go'] as $entry) {
    if ($source->locateName('ibyte-restic/'.$entry) === false) {
        throw new RuntimeException('Missing hardening review/test source: '.$entry);
    }
}
$source->close();
$zip = new ZipArchive;
$zip->open($root.'/release/Panel Plugin/'.$panelZipName);
foreach (['Recovery/FinalBackupPreparation.php', 'Recovery/RetainedMaintenance.php', 'Recovery/RepositoryExport.php', 'Jobs/PollRepositoryMaintenance.php', 'Support/FailureAlerts.php'] as $entry) {
    if ($zip->locateName('ibyte-restic/src/'.$entry) === false) {
        throw new RuntimeException('Missing hardening runtime module: '.$entry);
    }
}
$zip->close();
foreach (['amd64' => 62, 'arm64' => 183] as $arch => $machine) {
    foreach (['ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-', 'ibyte-node-tool-v3.0.0-preview.2-linux-', 'restic-0.19.1-linux-'] as $prefix) {
        $handle = fopen($release.'/'.$prefix.$arch, 'rb');
        $header = fread($handle, 20);
        fclose($handle);
        if (substr($header, 0, 4) !== "\x7fELF" || ord($header[4]) !== 2 || ord($header[5]) !== 1 || unpack('v', substr($header, 18, 2))[1] !== $machine) {
            throw new RuntimeException('Wrong binary format/architecture: '.$prefix.$arch);
        }
    }
}
echo "Two-folder distribution, plugin/source contents, manifest pins, and all six ELF architectures verified.\n";
