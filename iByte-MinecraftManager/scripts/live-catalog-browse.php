<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$adapter = new IByte\MinecraftManager\Catalog\Modrinth(app(IByte\MinecraftManager\Catalog\JsonTransport::class));
$results = [];
$base = ['query' => '', 'page' => 1, 'release_page' => 1, 'version' => '', 'loader' => '', 'experimental' => true];
foreach (['mod', 'plugin', 'modpack'] as $type) {
    $context = [...$base, 'type' => $type]; $data = $adapter->search($context);
    if (!$data['items']) { throw new RuntimeException('Expected an unfiltered public catalog'); }
    $results[$type] = ['total_observed' => $data['total'], 'first_page' => count($data['items']), 'version_filter' => '', 'loader_filter' => ''];
    if ($type === 'plugin') {
        $project = $data['items'][0]['id'];
        $release = $adapter->releases($project, [...$context, 'loader' => 'paper'])[0]['id'];
        $exact = $adapter->exact($project, $release);
        if (!in_array('plugin', $exact['project_types'], true)) { throw new RuntimeException('Plugin release was not classified correctly'); }
        $results[$type]['selected_paper_release'] = ['project' => $project, 'release' => $release, 'project_types' => $exact['project_types'], 'loaders' => $exact['loaders']];
    }
    echo 'PASS live Modrinth ', $type, ' catalog without implicit game/loader filters', "\n";
}
file_put_contents($root.'/docs/evidence/live-catalog-browse.json', json_encode(['scope' => 'Public Modrinth catalogs and exact plugin release metadata; no node writes, downloads or game execution', 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
