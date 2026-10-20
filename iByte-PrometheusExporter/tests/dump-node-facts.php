<?php

declare(strict_types=1);

// Fixture helper: inspect Node model facts for exporter use.
$node = '/opt/ibyte-prometheus/panel/app/Models/Node.php';
$file = new SplFileObject($node);
foreach ($file as $lineNumber => $line) {
    if (preg_match('/public function (servers|allocations)|getConnectionAddress|isUnderMaintenance|maintenance_mode/', $line)) {
        echo 'Node.php+', $lineNumber + 1, ': ', trim($line), PHP_EOL;
    }
}

chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$first = App\Models\Node::query()->first();
echo 'memory col: ', $first->memory, ' (MiB if binary prefix)', PHP_EOL;
echo 'disk col: ', $first->disk, PHP_EOL;
echo 'cpu col: ', $first->cpu, PHP_EOL;
echo 'maintenance: ', var_export($first->maintenance_mode, true), PHP_EOL;
echo 'upload_size MB: ', $first->upload_size, PHP_EOL;