<?php

declare(strict_types=1);

// Isolated fixture only: emulate an already-running installer with no plugin
// namespace registered. Actual native InstallPlugin job/assets/migrations run.
$root = dirname(__DIR__);
putenv('PANEL_PLUGIN_SAFE_MODE=true'); $_ENV['PANEL_PLUGIN_SAFE_MODE'] = 'true'; $_SERVER['PANEL_PLUGIN_SAFE_MODE'] = 'true';
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
// RC.1 registers plugin namespaces even for a not-installed plugin and has no
// safe-mode switch. Emulate a worker booted before that plugin existed by
// removing this namespace, never adding one. No plugin classes may be loaded.
if (class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller', false)) { throw new RuntimeException('Plugin helper already loaded before isolation'); }
$loader = require $root.'/.research/panel/vendor/autoload.php';
$loader->setPsr4('IByte\\MinecraftManager\\', []);
$loaded = class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller');
if ($loaded) { throw new RuntimeException('Regression precondition failed: helper is already loaded.'); }
$primed = false;
if (in_array('--prime-old-migration', $argv, true)) {
    $migrator = $app->make(Illuminate\Database\Migrations\Migrator::class);
    $resolve = new ReflectionMethod($migrator, 'resolvePath');
    try { $resolve->invoke($migrator, $root.'/.research/panel/plugins/ibyte-minecraft-manager/database/migrations/2026_10_16_000001_create_minecraft_manager_tables.php')->up(); }
    catch (Error $error) { if (!str_contains($error->getMessage(), 'SchemaInstaller')) { throw $error; } $primed = true; }
    if (!$primed) { throw new RuntimeException('Old migration did not fail as expected.'); }
}
file_put_contents($root.'/.build/ui-install-worker-ready.json', json_encode(['helper_autoloadable' => $loaded, 'started' => microtime(true), 'process_fake' => false, 'cached_old_failure' => $primed, 'pid' => getmypid()]));
exit($kernel->call('queue:work', ['connection' => 'database', '--queue' => 'default', '--sleep' => 1, '--max-time' => 180, '--timeout' => 1200, '--tries' => 1]));
