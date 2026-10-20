<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$catalog = app(IByte\MinecraftManager\Catalog\Catalog::class);
$context = ['type' => 'mod', 'version' => '1.21.1', 'loader' => 'fabric', 'query' => 'FerriteCore'];
$project = $catalog->search('modrinth', $context)['items'][0]['id'];
$id = $catalog->releases('modrinth', $project, $context)[0]['id'];
$release = $catalog->exact('modrinth', $project, $id);
$work = $root.'/.build/live-artifacts/modrinth'; if (!is_dir($work)) { mkdir($work, 0700, true); }
$prepared = app(IByte\MinecraftManager\Operations\InstallPreparer::class)->prepare($release, [...$context, 'kind' => 'package'], new IByte\MinecraftManager\Models\Profile, $work);
$result = ['provider' => 'modrinth', 'project' => $project, 'release' => $id, 'files' => array_map(fn ($file) => ['sha256' => $file['sha256'], 'size' => $file['size']], $prepared['files']),
    'scope' => 'Live compatible metadata, artifact streaming and publisher checksum verification; no node writes or execution'];
file_put_contents($root.'/docs/evidence/live-modrinth-artifact.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'PASS live Modrinth metadata and checksum-verified preparation: ', count($prepared['files']), " files\n";
