<?php

declare(strict_types=1);

// Fixture helper: verify suspended/admin facts the exporter can use.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Server::query()->get(['id', 'uuid', 'status']) as $server) {
    echo 'server ', $server->id, ' status=', var_export($server->status, true),
        ' suspended=', var_export($server->isSuspended(), true), PHP_EOL;
}
echo 'root_admin column exists: ', var_export(array_key_exists('root_admin',
    (new App\Models\User)->getAttributes()) || App\Models\User::query()->first()->root_admin !== null, true), PHP_EOL;
echo 'nodes columns: ', implode(',', array_keys(App\Models\Node::first()->getAttributes())), PHP_EOL;
echo 'suspended via enum: ', App\Models\ServerState::Suspended->value, PHP_EOL;