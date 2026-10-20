<?php

declare(strict_types=1);

// Fixture helper: search the panel source for root admin + suspended predicates.
$paths = [
    '/opt/ibyte-prometheus/panel/app/Models/User.php',
    '/opt/ibyte-prometheus/panel/app/Models/Server.php',
];
foreach ($paths as $path) {
    $file = new SplFileObject($path);
    foreach ($file as $lineNumber => $line) {
        if (preg_match('/root_admin|isSuspended|Suspended\b/', $line)) {
            echo basename($path), '+', $lineNumber + 1, ': ', trim($line), PHP_EOL;
        }
    }
}