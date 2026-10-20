<?php

declare(strict_types=1);

// Fixture helper: find how the panel identifies root admins (role or column).
$paths = [
    '/opt/ibyte-prometheus/panel/app/Models/User.php',
    '/opt/ibyte-prometheus/panel/app/Providers/AppServiceProvider.php',
    '/opt/ibyte-prometheus/panel/app/Http/Controller.php',
];
foreach ($paths as $path) {
    if (!is_file($path)) continue;
    $file = new SplFileObject($path);
    foreach ($file as $lineNumber => $line) {
        if (preg_match('/isRootAdmin|root_admin|ROOT_ADMIN|isRootAdmin\(/', $line)) {
            echo basename($path), '+', $lineNumber + 1, ': ', trim($line), PHP_EOL;
        }
    }
}
echo 'runtime check: user root_admin => ', var_export(App\Models\User::query()->first()->root_admin ?? null, true), PHP_EOL;