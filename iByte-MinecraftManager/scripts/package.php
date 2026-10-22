<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/plugin/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
$id = $manifest['id'];
$version = $manifest['version'];
$releaseDates = json_decode(file_get_contents($root.'/scripts/release-dates.json'), true, 16, JSON_THROW_ON_ERROR);
$releaseTime = isset($releaseDates[$version]) ? strtotime($releaseDates[$version]) : false;
if ($releaseTime === false) { throw new RuntimeException('Record a fixed, unique release timestamp before packaging.'); }
if ($releaseTime % 2 !== 0) { throw new RuntimeException('ZIP timestamps require an even second; record a fixed two-second release timestamp.'); }
if ($id !== 'ibyte-minecraft-manager' || !preg_match('/\A[0-9A-Za-z.+-]+\z/D', $version) || isset($manifest['meta'])) {
    throw new RuntimeException('Invalid distribution manifest.');
}
$candidate = in_array('--candidate', $argv, true);
$directory = $root . ($candidate ? '/.build' : '/release/Panel Plugin');
if (!is_dir($directory)) { mkdir($directory, 0770, true); }
$target = $directory . '/' . $id . '-v' . $version . '.zip';
$temporary = $root . '/.build/package-' . bin2hex(random_bytes(8)) . '.zip';
if (!is_dir($root . '/.build')) { mkdir($root . '/.build', 0770, true); }
$entries = ['LICENSE' => $root . '/LICENSE'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/plugin', FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/plugin/')));
    if (str_starts_with($relative, 'tests/')) { continue; }
    if (!$file->isFile() || $file->isLink() || !preg_match('/\.(?:php|json|md)\z/D', $relative)) {
        throw new RuntimeException('Unexpected package input: ' . $relative);
    }
    $entries[$relative] = $file->getPathname();
}
ksort($entries, SORT_STRING);
$zip = new ZipArchive;
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Cannot create ZIP.'); }
$hashes = [];
foreach ($entries as $name => $file) {
    $path = $id . '/' . $name;
    $content = file_get_contents($file);
    $zip->addFromString($path, $content);
    $zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9);
    // Stable within this release, distinct across releases: OPcache compares mtimes.
    $zip->setMtimeName($path, $releaseTime);
    $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    $hashes[] = ['path' => $name, 'sha256' => hash('sha256', $content)];
}
$zip->close();
$hash = hash_file('sha256', $temporary);
if (!$candidate && is_file($target) && !hash_equals(hash_file('sha256', $target), $hash)) {
    unlink($temporary);
    throw new RuntimeException('Immutable delivered artifact exists with different bytes. Increment the version.');
}
if (is_file($target)) { unlink($target); }
rename($temporary, $target);
file_put_contents($root . '/.build/package-provenance.json', json_encode(['version' => $version, 'source_date_epoch' => $releaseTime, 'sha256' => $hash, 'inputs' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
if (!$candidate) {
    $readme = "# iByte Minecraft Manager $version\n\nImport this ZIP through Pelican Admin > Plugins and install/update it. No Wings changes are needed.\n\nTake a backup before installing. The reminder is advisory. Failed/cancelled installs keep partial files/startup and release their slot; no automatic rollback is performed.\n\nDownloads and extraction run on Wings. The Panel reads small manifest/index JSON and filesystem metadata, with completion/expected-size checks rather than full-payload checksum verification. Published hashes remain expected metadata. Temporary operation folders are cleaned afterward.\n\nThis package's version is $version. Packaging does not assert runtime or browser acceptance. Already-running workers retain loaded classes until refreshed.\n";
    file_put_contents($directory . '/README.md', $readme);
}
echo ($candidate ? 'Candidate: ' : 'Release: '), basename($target), "\n", 'SHA-256: ', $hash, "\n", count($entries), " files; root folder verified by construction.\n";
