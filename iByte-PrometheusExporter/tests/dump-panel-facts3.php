<?php

declare(strict_types=1);

// Fixture helper: count users with the ROOT_ADMIN role using the panel's own predicate.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'root admins: ', App\Models\User::query()->get()->filter(
    fn (App\Models\User $user) => $user->isRootAdmin()
)->count(), PHP_EOL;
echo 'maintenance nodes: ', App\Models\Node::query()->where('maintenance_mode', true)->count(), PHP_EOL;
echo 'unassigned allocations: ', App\Models\Allocation::query()->whereNull('server_id')->count(), PHP_EOL;