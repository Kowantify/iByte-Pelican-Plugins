<?php

declare(strict_types=1);

// Fixture helper: write the current scrape-token file's hash as the plugin token hash
// so the monitoring stack's bearer_file tests the real credential end to end.
if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') {
    throw new RuntimeException('Disposable distribution required');
}
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings = IByte\PrometheusExporter\Support\Settings::read();
$settings['token_hash'] = hash('sha256', trim((string) file_get_contents('/opt/ibyte-prometheus/scrape-token')));
IByte\PrometheusExporter\Support\Settings::write($settings);
echo 'token hash now: ', IByte\PrometheusExporter\Support\Settings::read()['token_hash'], "\n";