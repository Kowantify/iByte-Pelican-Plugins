<?php

declare(strict_types=1);

require dirname(__DIR__) . '/plugin/tests/bootstrap.php';
$root = dirname(__DIR__);
$version = json_decode(file_get_contents($root . '/plugin/plugin.json'), true, 64, JSON_THROW_ON_ERROR)['version'];
$archive = $root . '/.build/ibyte-minecraft-manager-v' . $version . '.zip';
$zip = new ZipArchive;
check($zip->open($archive, ZipArchive::RDONLY) === true, 'Candidate ZIP missing');
$files = 0;
$releaseDates = json_decode(file_get_contents($root.'/scripts/release-dates.json'), true, 16, JSON_THROW_ON_ERROR);
$expectedMtime = strtotime($releaseDates[$version]);
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    check($zip->statIndex($index)['mtime'] === $expectedMtime && $expectedMtime !== 315532800, 'ZIP release timestamp would prevent PHP cache refresh');
    IByte\MinecraftManager\Support\Input::path($name);
    check(str_starts_with($name, 'ibyte-minecraft-manager/'), 'Wrong ZIP root');
    check(!preg_match('~(?:^|/)(?:tests|vendor|node_modules|\.research|\.tools|\.env)(?:/|$)~', $name), 'Unexpected packaged tooling');
    $content = $zip->getFromIndex($index);
    check(!preg_match('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', $content), 'Credential-like value in ZIP');
    if (str_contains($name, '/src/')) {
        check(!preg_match('/\b(?:shell_exec|proc_open|passthru|system|popen|exec)\s*\(/', $content), 'Host execution in plugin');
    }
    $files++;
}
$manifest = json_decode($zip->getFromName('ibyte-minecraft-manager/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
check(!isset($manifest['meta']) && $manifest['api_version'] === 1, 'Invalid distribution metadata');
$zip->close();
echo "PASS $files packaged files: one root; no tooling, credential-like values or host execution. Stock Wings file operations are intentional.\n";
echo "This structural scan complements gitleaks and dependency audit; it is not a complete security proof.\n";
