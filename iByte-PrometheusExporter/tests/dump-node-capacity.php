<?php

declare(strict_types=1);

// Fixture helper: compute node capacity sums the way the panel computes them.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Node::query()->get() as $node) {
    echo 'node ', $node->id, ': memory=', $node->memory,
        ' mem_over=', $node->memory_overallocate,
        ' disk=', $node->disk, ' disk_over=', $node->disk_overallocate,
        ' cpu=', $node->cpu, ' cpu_over=', $node->cpu_overallocate,
        ' servers=', $node->servers()->count(),
        ' alloc_total=', $node->allocations()->count(),
        ' alloc_used=', $node->allocations()->whereNotNull('server_id')->count(), PHP_EOL;
}

// Panel "sum of server allocations" per node - a useful sysadmin figure.
$binaryPrefix = (bool) config('panel.use_binary_prefix', true);
$factor = $binaryPrefix ? 1024 * 1024 : 1000 * 1000;
foreach (App\Models\Node::query()->with('servers')->get() as $node) {
    $memory = $node->servers->sum('memory') * $factor;
    $disk = $node->servers->sum('disk') * $factor;
    $cpu = $node->servers->sum('cpu');
    echo 'node ', $node->id, ' allocated: memory=', $memory, 'B disk=', $disk, 'B cpu=', $cpu, '%', PHP_EOL;
}