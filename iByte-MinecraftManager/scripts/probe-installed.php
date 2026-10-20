<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (!class_exists(IByte\MinecraftManager\Operations\CheckReview::class)) { throw new RuntimeException('Native autoload missing.'); }
    if (!app()->bound(IByte\MinecraftManager\Catalog\JsonTransport::class)) { throw new RuntimeException('Native provider not registered.'); }
    $pages = [];
    foreach (['server', 'admin'] as $id) {
        $pages[$id] = count(array_filter(Filament\Facades\Filament::getPanel($id)->getPages(), fn ($class) => str_starts_with($class, 'IByte\\MinecraftManager\\')));
        if ($pages[$id] !== 1) { throw new RuntimeException('Expected exactly one page in ' . $id); }
    }
    echo "PASS fresh-process native autoload, providers, one server page and one admin page\n";
    if (in_array('--views', $argv, true)) {
        $directory = plugin_path('ibyte-minecraft-manager', 'resources', 'views');
        echo 'View directory: ', $directory, "\n", 'Compiled directory: ', config('view.compiled'), "\n";
        echo 'Matched templates: ', count(glob($directory . '/*.blade.php') ?: []), "\n";
        IByte\MinecraftManager\Support\ViewCache::refresh(app('blade.compiler'));
    }
    if (in_array('--queue', $argv, true)) {
        $id = json_decode(file_get_contents($root . '/.build/queue-probe.json'), true, 64, JSON_THROW_ON_ERROR)['operation'];
        $operation = IByte\MinecraftManager\Models\Operation::findOrFail($id);
        if ($operation->status !== 'blocked' || $operation->error_code !== 'permission_denied' || $operation->server_slot !== null) {
            throw new RuntimeException('Fresh worker did not enforce queued revocation.');
        }
        echo "PASS real database queue, fresh native worker and permission revocation before provider/file work\n";
    }
    if (in_array('--install-queue', $argv, true)) {
        $id = json_decode(file_get_contents($root.'/.build/install-queue-probe.json'), true, 64, JSON_THROW_ON_ERROR)['operation'];
        $op = IByte\MinecraftManager\Models\Operation::findOrFail($id);
        if ($op->status !== 'blocked' || $op->error_code !== 'permission_denied' || $op->server_slot !== null) { throw new RuntimeException('Fresh install worker did not enforce revocation.'); }
        echo "PASS dedicated install queue and fresh minecraft-manager:work enforce install permission revocation\n";
    }
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
