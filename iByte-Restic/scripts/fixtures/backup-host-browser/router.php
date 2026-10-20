<?php

use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Providers\ResticProvider;
use IByte\Restic\ResticPlugin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// Local disposable browser fixture only. No operator config, DB or credentials.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Loopback disposable fixture only.');
}
$root = dirname(__DIR__, 3);
if (! is_dir($root.'/.build')) {
    mkdir($root.'/.build', 0700, true);
}
$panel = $root.'/.upstream/panel';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(js|css|fonts)/[a-zA-Z0-9_./-]+$#D', $path) && ! str_contains($path, '..')) {
    $asset = $panel.'/public'.$path;
    if (is_file($asset)) {
        header('Content-Type: '.(str_ends_with($path, '.js') ? 'application/javascript' : (str_ends_with($path, '.css') ? 'text/css' : 'application/octet-stream')));
        readfile($asset);

        return;
    }
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'APP_DEBUG' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/.build/browser-form.sqlite', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'APP_URL' => 'http://127.0.0.1:8098'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (! file_exists($root.'/.build/browser-form.sqlite')) {
    touch($root.'/.build/browser-form.sqlite');
}
$loader = require $panel.'/vendor/autoload.php';
$loader->addPsr4('IByte\\Restic\\', $root.'/panel-plugin/src/');
$app = require $panel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => realpath($root.'/.build/browser-form.sqlite')]);
DB::purge();
$app->register(ResticProvider::class);
if (! Schema::hasTable('users')) {
    Artisan::call('migrate', ['--force' => true]);
    $role = Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole($role);
}
if (! Schema::hasTable('ibyte_restic_operations')) {
    (require $root.'/panel-plugin/database/migrations/2026_10_14_000001_create_ibyte_restic_operations.php')->up();
    (require $root.'/panel-plugin/database/migrations/2026_10_15_000001_add_ibyte_restic_storage_metrics.php')->up();
    (require $root.'/panel-plugin/database/migrations/2026_10_15_000002_create_ibyte_restic_recovery_inventory.php')->up();
    (require $root.'/panel-plugin/database/migrations/2026_10_15_000003_create_ibyte_restic_retention.php')->up();
    (require $root.'/panel-plugin/database/migrations/2026_10_15_000004_create_ibyte_restic_key_history.php')->up();
}
if (! Node::exists()) {
    Node::factory()->create();
}
Auth::login(User::firstOrFail());
Filament::setCurrentPanel(Filament::getPanel('admin'));
(new ResticPlugin)->register(Filament::getPanel('admin'));
Route::prefix('admin')->name('filament.admin.')->group(fn () => RecoveryResource::registerRoutes(Filament::getPanel('admin')));
app('router')->getRoutes()->refreshNameLookups();
Route::middleware('web')->get('/form-fixture', function () {
    return Blade::render('<!doctype html><html><head><meta charset="utf-8">@filamentStyles</head><body>@livewire(\App\Filament\Admin\Resources\BackupHosts\Pages\CreateBackupHost::class) @filamentScripts @livewireScripts</body></html>');
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
