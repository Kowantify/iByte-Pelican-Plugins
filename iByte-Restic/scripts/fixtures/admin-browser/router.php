<?php

// Disposable browser fixture. Never copy into an installed Panel.
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Providers\ResticProvider;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\ResticPlugin;
use IByte\Restic\ResticSchema;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Loopback disposable fixture only.');
}
define('LARAVEL_START', microtime(true));
$root = dirname(__DIR__, 3);
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
if (! is_dir($root.'/.build')) {
    mkdir($root.'/.build', 0700, true);
}
$database = $root.'/.build/browser-admin.sqlite';
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('v', 32)), 'APP_DEBUG' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'APP_URL' => 'http://127.0.0.1:8099'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (! file_exists($database)) {
    touch($database);
}
$loader = require $panel.'/vendor/autoload.php';
$loader->addPsr4('IByte\\Restic\\', $root.'/panel-plugin/src/');
$app = require $panel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => realpath($database), 'app.installed' => true, 'panel.auth.2fa_required' => 0]);
DB::purge();
$app->register(ResticProvider::class);
Http::preventStrayRequests();
// Exercise actual Filament assets/layout/Livewire. The separate application Vite
// passkey/clipboard hooks are outside this bounded fixture; no build is claimed.
$app->instance(Vite::class, new class extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null)
    {
        return new HtmlString('');
    }
});
if (! Schema::hasTable('users')) {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['username' => 'fixture-admin', 'password' => bcrypt('browser-fixture')]);
    $admin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
    Node::factory()->create(['name' => 'Disposable node']);
}
foreach (glob($root.'/panel-plugin/database/migrations/*.php') as $file) {
    (require $file)->up();
}
Auth::login(User::where('username', 'fixture-admin')->firstOrFail());
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
(new ResticPlugin)->register(Filament::getPanel('admin'));
Route::prefix('admin')->name('filament.admin.')->group(fn () => RecoveryResource::registerRoutes(Filament::getPanel('admin')));
app('router')->getRoutes()->refreshNameLookups();
if (! RecoveryRepository::exists()) {
    $host = BackupHost::create(['name' => 'Local Restic', 'schema' => 'restic', 'configuration' => ResticSchema::DEFAULT_CONFIGURATION]);
    $host->nodes()->attach(Node::firstOrFail());
    foreach (['Valheim', 'Minecraft (measurement pending)', 'Former client (retained)'] as $index => $name) {
        $uuid = (string) Str::uuid();
        $repo = RecoveryRepository::create(['host_id' => $host->id, 'node_id' => Node::firstOrFail()->id, 'server_uuid' => $uuid, 'profile' => 'default', 'backend' => 'local', 'owner_id' => 42 + $index, 'owner_username' => 'fixture-owner-'.$index, 'server_name' => $name, 'metadata' => ['host_name' => $host->name, 'repository' => '/var/lib/ibyte-restic/repositories/host-'.$host->id.'/node-1/'.$uuid] + ($index === 2 ? ['archived_at' => now()->toIso8601String(), 'archive_reason' => 'Server deleted'] : []), 'owner_history' => [], 'secret' => Crypt::encryptString(json_encode(['password_base64' => base64_encode('disposable-key')])), 'captured_at' => now()]);
        if ($index === 1) {
            continue;
        }
        DB::table('ibyte_restic_operations')->insert(['request_uuid' => (string) Str::uuid(), 'backup_uuid' => (string) Str::uuid(), 'host_id' => $host->id, 'node_id' => $repo->node_id, 'server_uuid' => $uuid, 'operation' => 'create', 'status' => 'succeeded', 'payload' => '{}', 'metrics' => json_encode(['repository' => ['repository_file_bytes' => 2 * 1024 ** 3, 'logical_bytes' => 5 * 1024 ** 3, 'total_uncompressed_size' => 2.5 * 1024 ** 3, 'total_size' => 2 * 1024 ** 3, 'snapshots_count' => 2, 'compression_ratio' => 1.25, 'compression_space_saving' => 20, 'snapshots' => []]]), 'created_at' => now(), 'updated_at' => now()->subMinutes(15)]);
    }
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
