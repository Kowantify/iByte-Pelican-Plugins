<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
echo 'PHP ', PHP_VERSION, PHP_EOL;
foreach (['laravel/framework', 'filament/filament', 'livewire/livewire', 'guzzlehttp/guzzle'] as $package) {
    echo $package, ' ', Composer\InstalledVersions::getPrettyVersion($package), PHP_EOL;
}
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'Panel bootstrap OK', PHP_EOL;
