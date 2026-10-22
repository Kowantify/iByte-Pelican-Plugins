<?php

declare(strict_types=1);
$root = dirname(__DIR__);
$pins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
$panelRelease = $pins['panel_release'] ?? $pins['release'];
$out = $root.'/release/Panel Plugin/ibyte-restic-v'.$panelRelease.'.zip';
if (! is_dir(dirname($out))) {
    mkdir(dirname($out), 0755, true);
}
$manifest = json_decode(file_get_contents($root.'/panel-plugin/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['id'] !== 'ibyte-restic' || isset($manifest['meta'])) {
    throw new RuntimeException('Invalid public plugin manifest');
}
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/panel-plugin', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/panel-plugin/')));
    }
}
sort($files, SORT_STRING);
$zip = new ZipArchive;
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('ZIP creation failed');
}
foreach ($files as $file) {
    $entry = 'ibyte-restic/'.$file;
    $zip->addFromString($entry, str_replace("\r\n", "\n", file_get_contents($root.'/panel-plugin/'.$file)));
    $zip->setMtimeName($entry, 1791936000); // fixed packaging epoch; deterministic ZIP
    $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
if (! $zip->close()) {
    throw new RuntimeException('ZIP close failed');
}
echo basename($out), ' ', hash_file('sha256', $out), "\n";
