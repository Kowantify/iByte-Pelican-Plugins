<?php

declare(strict_types=1);

// Fixture helper: inspect plugin model state and re-register plugin providers.
// Run inside the acceptance WSL distribution only.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$plugin = App\Models\Plugin::find('ibyte-prometheus-exporter');
echo 'status: ', $plugin->status->value, "\n";
echo 'meta: ', json_encode($plugin->meta), "\n";

// Clear opcache/config caches that may hold the old plugin command registrations.
foreach (['bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php'] as $file) {
    if (is_file($file)) {
        unlink($file);
        echo 'removed ', $file, "\n";
    }
}
echo PHP_EOL;