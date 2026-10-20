<?php

declare(strict_types=1);

// Fixture helper: read the current per-node collector snapshots from the disposable Panel.
// Run inside the acceptance WSL distribution only.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['1', '3'] as $nodeId) {
    $snapshot = Cache::get("ibyte-prometheus:node:$nodeId");
    if ($snapshot === null) {
        echo "node $nodeId: no snapshot\n";
        continue;
    }
    echo 'node ', $nodeId, ': ', json_encode($snapshot, JSON_PRETTY_PRINT), "\n";
}