<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$components = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sqlite' => Illuminate\Support\Facades\DB::selectOne('select sqlite_version() as version')->version];
foreach (['laravel/framework', 'filament/filament', 'livewire/livewire', 'symfony/http-foundation'] as $name) {
    $components[$name] = Composer\InstalledVersions::getPrettyVersion($name);
}
$eggFiles = ['java/paper/egg-paper.yaml', 'java/fabric/egg-fabric.yaml', 'java/forge/egg-forge-minecraft.yaml',
    'java/neoforge/egg-neo-forge.json', 'java/quilt/egg-quilt.yaml', 'proxy/java/velocity/egg-velocity.json'];
$eggs = [];
foreach ($eggFiles as $relative) {
    $file = $root . '/.research/eggs/' . $relative;
    if (!is_file($file)) { $eggs[$relative] = ['status' => 'path_not_present']; continue; }
    $egg = str_ends_with($file, '.yaml') ? Symfony\Component\Yaml\Yaml::parseFile($file) : json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    $eggs[$relative] = ['name' => $egg['name'] ?? '', 'startup' => $egg['startup_commands'] ?? $egg['startup'] ?? null,
        'images' => $egg['docker_images'] ?? [], 'install_runtime_tested' => false,
        'install_container' => $egg['scripts']['installation']['container'] ?? null];
}
file_put_contents($root . '/docs/evidence/environment.json', json_encode(['date' => '2026-10-16', 'components' => $components, 'inspected_eggs' => $eggs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Recorded actual components and source-only egg startup/image inspection.\n";
