<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require __DIR__.'/package-panel-plugin.php';
$panelZipName = basename($out);
copy($out, $root.'/.build/release-artifacts/'.$panelZipName);
copy($root.'/docs/INSTALL_PANEL.md', $root.'/release/Panel Plugin/README.md');
$nodeFiles = [];
foreach (['amd64', 'arm64'] as $arch) {
    foreach (['ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-', 'ibyte-node-tool-v3.0.0-preview.2-linux-', 'restic-0.19.1-linux-'] as $prefix) {
        $nodeFiles[] = $prefix.$arch;
    }
}
sort($nodeFiles, SORT_STRING);
$nodeManifest = '';
foreach ($nodeFiles as $file) {
    if (! is_file($root.'/.build/release-artifacts/'.$file)) {
        throw new RuntimeException('Build the node artifact first: '.$file);
    }
    $nodeManifest .= hash_file('sha256', $root.'/.build/release-artifacts/'.$file).'  '.$file."\n";
}
file_put_contents($root.'/.build/release-artifacts/NODE-SHA256SUMS', $nodeManifest);
$repository = getenv('GITHUB_REPOSITORY') ?: '';
if ($repository !== '' && ! preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#D', $repository)) {
    throw new RuntimeException('Invalid release repository');
}
$releaseUrl = $repository === '' ? '' : 'https://github.com/'.$repository.'/releases/download/v3.0.0-preview.2';
foreach (['install-wings-restic.sh', 'uninstall-wings-restic.sh'] as $script) {
    $contents = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/'.$script));
    if ($script === 'install-wings-restic.sh') {
        if (substr_count($contents, '__IBYTE_NODE_MANIFEST_SHA256__') !== 1 || substr_count($contents, '__IBYTE_DEFAULT_RELEASE_URL__') !== 1) {
            throw new RuntimeException('Installer pin template changed unexpectedly');
        }
        $contents = str_replace(['__IBYTE_NODE_MANIFEST_SHA256__', '__IBYTE_DEFAULT_RELEASE_URL__'], [hash('sha256', $nodeManifest), $releaseUrl], $contents);
    }
    file_put_contents($root.'/.build/release-artifacts/'.$script, $contents);
}
foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $file) {
    copy($root.'/'.$file, $root.'/.build/release-artifacts/'.$file);
}
copy($root.'/.upstream/wings/LICENSE', $root.'/.build/release-artifacts/PELICAN-WINGS-LICENSE.txt');
copy($root.'/docs/RECOVERY.md', $root.'/.build/release-artifacts/RECOVERY.md');
$nodeLicenses = ['iByte Restic' => file_get_contents($root.'/LICENSE'), 'Pelican Wings' => file_get_contents($root.'/.upstream/wings/LICENSE'), 'Restic' => file_get_contents($root.'/.upstream/restic/LICENSE')];
require __DIR__.'/package-installer.php';
// Ship standalone source including docs, exact upstream pins and reproducible two-line patch.
$source = new ZipArchive;
$sourcePath = $root.'/.build/release-artifacts/ibyte-restic-pelican-v'.$panelRelease.'-source.zip';
if ($source->open($sourcePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Source ZIP failed');
}
$paths = ['panel-plugin', 'wings-addon', 'restic-runtime', 'scripts', 'compatibility', 'docs', '.github'];
foreach (array_merge(['AGENTS.md', 'README.md', 'LICENSE', 'CHANGELOG.md', '.gitignore', '.gitleaks.toml'], $paths) as $path) {
    if (is_file($root.'/'.$path)) {
        $files = [$root.'/'.$path];
    } else {
        $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path, FilesystemIterator::SKIP_DOTS)));
    }
    foreach ($files as $file) {
        $full = (string) $file;
        if (! is_file($full)) {
            continue;
        }
        $name = str_replace('\\', '/', substr($full, strlen($root) + 1));
        // The external digest report includes the source ZIP/manifest hashes; exclude circular inputs.
        if (in_array($name, ['docs/ARTIFACTS.md', 'docs/evidence/hardening-package.txt', 'docs/evidence/hardening-validation.txt', 'docs/evidence/hardening-manifests.txt', 'docs/evidence/compatibility-package.txt', 'docs/evidence/compatibility-validation.txt', 'docs/evidence/alerts-package.txt', 'docs/evidence/alerts-validation.txt', 'docs/evidence/destruction-package.txt', 'docs/evidence/destruction-validation.txt'], true)) {
            continue;
        }
        $source->addFromString('ibyte-restic/'.$name, str_replace("\r\n", "\n", file_get_contents($full)));
        $source->setMtimeName('ibyte-restic/'.$name, 1791936000);
    }
}
$source->close();
$distribution = new ZipArchive;
$distribution->open($root.'/.build/release-artifacts/ibyte-restic-install-v'.$panelRelease.'.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$wingsBundle = new ZipArchive;
$wingsBundle->open($root.'/.build/release-artifacts/ibyte-restic-wings-v3.0.0-preview.2.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$deliveryFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/release', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $deliveryFiles[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/release/')));
    }
}
sort($deliveryFiles, SORT_STRING);
foreach ($deliveryFiles as $file) {
    $archives = str_starts_with($file, 'Wings Installer/') ? [$distribution, $wingsBundle] : [$distribution];
    foreach ($archives as $archive) {
        $archive->addFile($root.'/release/'.$file, $file);
        $archive->setCompressionName($file, str_ends_with($file, '.zip') ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, 6);
        $archive->setMtimeName($file, 1791936000);
        $executable = str_ends_with($file, '.sh') || str_contains($file, '-linux-');
        $archive->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, ($executable ? 0100755 : 0100644) << 16);
    }
}
$distribution->close();
$wingsBundle->close();
$files = array_filter(glob($root.'/.build/release-artifacts/*'), fn ($file) => is_file($file) && basename($file) !== 'SHA256SUMS');
sort($files, SORT_STRING);
$manifest = '';
foreach ($files as $file) {
    $manifest .= hash_file('sha256', $file).'  '.basename($file)."\n";
}
file_put_contents($root.'/.build/release-artifacts/SHA256SUMS', $manifest);
echo 'SHA256SUMS SHA256: ', hash_file('sha256', $root.'/.build/release-artifacts/SHA256SUMS'), "\n";
