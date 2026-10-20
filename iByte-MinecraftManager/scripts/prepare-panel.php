<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$panel = $root . '/.research/panel';
if (!is_file($panel . '/vendor/autoload.php')) {
    throw new RuntimeException('Prepare a separate pinned official Panel checkout and install its locked dependencies first.');
}
foreach (['bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'plugins'] as $dir) {
    if (!is_dir($panel . '/' . $dir)) { mkdir($panel . '/' . $dir, 0770, true); }
}
if (!is_file($panel . '/.env')) {
    $env = [
        'APP_ENV=local', 'APP_DEBUG=false', 'APP_KEY=base64:' . base64_encode(random_bytes(32)),
        'APP_URL=http://127.0.0.1:8096', 'APP_INSTALLED=true', 'APP_LOCALE=en',
        'DB_CONNECTION=sqlite', 'DB_DATABASE=imm-preview.sqlite', 'CACHE_STORE=file', 'SESSION_DRIVER=file',
        'QUEUE_CONNECTION=database', 'MAIL_MAILER=log',
    ];
    file_put_contents($panel . '/.env', implode("\n", $env) . "\n");
}
if (!is_file($panel . '/database/imm-preview.sqlite')) { touch($panel . '/database/imm-preview.sqlite'); }
echo "Disposable Panel environment prepared in .research/panel; no existing environment reused.\n";
