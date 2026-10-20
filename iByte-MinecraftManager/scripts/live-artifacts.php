<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mc = app(IByte\MinecraftManager\Catalog\McJars::class);
$profile = new IByte\MinecraftManager\Models\Profile(['jar_path' => 'server.jar']);
$contracts = json_decode(file_get_contents($root.'/.build/runtime-contracts.json'), true, 64, JSON_THROW_ON_ERROR);
$result = [];
foreach ($contracts as $type => $contract) {
    if (isset($argv[1]) && $argv[1] !== $type) { continue; }
    try {
        $release = $mc->exact($type, $contract['version'], $contract['build']['uuid']);
        $work = $root.'/.build/live-artifacts/'.$type;
        if (!is_dir($work)) { mkdir($work, 0700, true); }
        $prepared = app(IByte\MinecraftManager\Operations\InstallPreparer::class)->prepare($release, ['kind' => 'software'], $profile, $work);
        $files = $prepared['files'];
        $result[$type] = ['passed' => true, 'version' => $release['version'], 'build' => $release['id'], 'java' => $release['java'],
            'files' => count($files), 'bytes' => array_sum(array_column($files, 'size')), 'launcher_sha256' => $files[$prepared['runtime']['launcher']]['sha256'],
            'source_integrity' => 'MCJars HTTPS recipe and size; local SHA-256 and archive CRC; no publisher hash supplied by recipe'];
        echo 'PASS ', $type, ' ', count($files), " verified prepared files\n";
    } catch (Throwable $error) {
        $result[$type] = ['passed' => false, 'reason' => $error instanceof IByte\MinecraftManager\Support\Failure ? $error->reason : 'preparation_failed'];
        echo 'FAIL ', $type, ' ', $result[$type]['reason'], "\n";
    }
}
file_put_contents($root.'/docs/evidence/live-artifacts.json', json_encode(['scope' => 'Live metadata, streamed disk downloads and safe runtime preparation only; no JAR execution, node writes or startup', 'results' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
exit(count(array_filter($result, fn ($row) => !$row['passed'])) ? 1 : 0);
