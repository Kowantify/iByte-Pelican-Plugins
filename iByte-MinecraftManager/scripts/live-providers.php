<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings = app(IByte\MinecraftManager\Support\Settings::class);
$http = app(IByte\MinecraftManager\Catalog\JsonTransport::class);
$failures = 0;
$results = [];
function smoke(string $name, callable $test): void
{
    global $results, $failures;
    try { $detail = $test(); echo 'PASS ', $name, ': ', $detail, "\n"; $results[$name] = ['status' => 'pass', 'detail' => $detail]; }
    catch (Throwable $error) {
        $failures++;
        $code = $error instanceof IByte\MinecraftManager\Support\Failure ? $error->reason : 'test_failed';
        echo 'FAIL ', $name, ': ', $code, "\n"; $results[$name] = ['status' => 'fail', 'code' => $code];
    }
}
smoke('Modrinth v2 search', function () use ($http): string {
    $response = $http->get('modrinth', '/v2/search', ['query' => 'fabric', 'limit' => 1]);
    if (!isset($response['hits'][0]['project_id'])) { throw new RuntimeException; }
    return 'public search schema observed; one project';
});
smoke('MCJars full type discovery', function () use ($http, $root): string {
    $types = (new IByte\MinecraftManager\Catalog\McJars($http))->types();
    file_put_contents($root . '/compatibility/software-coverage.json', json_encode([
        'observed_at' => '2026-10-16', 'api' => '/api/v2/types',
        'types' => array_map(fn ($t) => ['id' => $t['id'], 'group' => $t['group'], 'catalog_observed' => true,
            'installation_enabled' => null, 'installation_policy' => 'per-build-preflight', 'startup_tested' => false, 'reason' => $t['reason']], $types),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return count($types) . ' types observed; installability depends on the exact build recipe';
});
smoke('MCJars v3 versions and exact build', function () use ($http): string {
    $adapter = new IByte\MinecraftManager\Catalog\McJars($http);
    $versions = $adapter->versions('VANILLA');
    $version = $versions['items'][0]['id'] ?? throw new RuntimeException;
    $builds = $adapter->builds('VANILLA', $version);
    $id = $builds['items'][0]['uuid'] ?? throw new RuntimeException;
    $release = $adapter->exact('VANILLA', $version, $id);
    return 'version ' . $version . '; exact build identity and recipe metadata observed';
});
$key = getenv('IMM_TEST_CF_KEY');
if ($key !== false && $key !== '') {
    $previous = $settings->credential('curseforge');
    try {
        $settings->replaceCredential('curseforge', $key);
        unset($key); putenv('IMM_TEST_CF_KEY');
        smoke('CurseForge authorized categories and search', function () use ($http): string {
            $classes = $http->get('curseforge', '/v1/categories', ['gameId' => 432, 'classesOnly' => true]);
            $ids = array_column($classes['data'] ?? [], 'id');
            foreach ([5, 6, 4471] as $id) { if (!in_array($id, $ids, true)) { throw new RuntimeException; } }
            $result = $http->get('curseforge', '/v1/mods/search', ['gameId' => 432, 'classId' => 6, 'pageSize' => 1]);
            if (!isset($result['data'][0]['id'])) { throw new RuntimeException; }
            return 'authorized metadata only; class IDs 5/6/4471 confirmed; one project';
        });
    } finally { $settings->replaceCredential('curseforge', $previous); }
} else {
    echo "SKIP CurseForge: IMM_TEST_CF_KEY absent\n"; $results['CurseForge authorized search'] = ['status' => 'skip', 'reason' => 'credential_not_supplied_to_process'];
}
file_put_contents($root . '/docs/evidence/live-providers.json', json_encode(['date' => '2026-10-16', 'tests' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit($failures ? 1 : 0);
