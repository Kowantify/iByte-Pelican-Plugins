<?php
$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$http = app(IByte\MinecraftManager\Catalog\JsonTransport::class);
$adapter = new IByte\MinecraftManager\Catalog\McJars($http);
$result = [];
foreach (['VANILLA', 'PAPER', 'FABRIC', 'FORGE', 'NEOFORGE', 'QUILT', 'VELOCITY'] as $type) {
    try {
        $versions = $adapter->versions($type);
        $version = collect($versions['items'])->firstWhere('type', 'RELEASE')['id'] ?? $versions['items'][0]['id'];
        $build = $adapter->builds($type, $version)['items'][0];
        $result[$type] = ['version' => $version, 'build' => $build];
    } catch (Throwable $error) { $result[$type] = ['error' => $error instanceof IByte\MinecraftManager\Support\Failure ? $error->reason : 'research_failed']; }
}
file_put_contents($root . '/.build/runtime-contracts.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
foreach ($result as $type => $data) {
    echo $type, ' ', $data['version'] ?? $data['error'], ' ', json_encode($data['build']['installation'] ?? []), "\n";
}
