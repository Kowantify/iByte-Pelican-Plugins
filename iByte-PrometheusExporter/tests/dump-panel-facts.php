<?php

declare(strict_types=1);

// Fixture helper: dump panel-side inventory facts useful for a sysadmin exporter:
// users, nodes, allocations, egg/backup/transfer counts, server states and status.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'users total: ', App\Models\User::count(), PHP_EOL;
echo 'admin-role users: ', App\Models\User::where('root_admin', true)->count(), PHP_EOL;
echo 'nodes: ', App\Models\Node::count(), PHP_EOL;
echo 'servers: ', App\Models\Server::count(), PHP_EOL;
echo 'servers suspended: ', App\Models\Server::query()->where('status', App\Models\Server::STATUS_SUSPENDED)->count(), PHP_EOL;
echo 'allocations: ', App\Models\Allocation::count(), PHP_EOL;
echo 'allocations assigned: ', App\Models\Allocation::whereNotNull('server_id')->count(), PHP_EOL;
echo 'eggs: ', App\Models\Egg::count(), PHP_EOL;
echo 'backups non-failed: ', App\Models\Backup::nonFailed()->count(), PHP_EOL;
echo 'backups bytes: ', App\Models\Backup::nonFailed()->sum('bytes'), PHP_EOL;
echo 'failed backups: ', App\Models\Backup::failed()->count(), PHP_EOL;

$node = App\Models\Node::first();
echo 'node columns: ', implode(',', array_keys($node->getAttributes())), PHP_EOL;