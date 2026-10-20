<?php

declare(strict_types=1);

// TEST HARNESS ONLY. Not packaged. Bind PHP's server to 127.0.0.1, never a LAN address.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit;
}
$root = dirname(__DIR__);
$public = realpath($root . '/.research/panel/public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static = realpath($public . '/' . ltrim($path, '/'));
if ($static && str_starts_with($static, $public . DIRECTORY_SEPARATOR) && is_file($static)) { return false; }
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
require $root . '/plugin/tests/bootstrap.php';
$app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class, new FixtureTransport(require $root . '/plugin/tests/catalog-fixture.php'));
$app->bind(IByte\MinecraftManager\Operations\NodeFiles::class,fn($app,$params)=>new class($params['server']) extends IByte\MinecraftManager\Operations\NodeFiles {
    // Test-only stopped node. Browser fixtures never reach a real server.
    public function assertStopped():void {}
});
Illuminate\Support\Facades\Route::middleware('web')->post('/__fixture/runtime/{type}/{version}', function (string $type, string $version) use ($root) {
    $fixture=json_decode(file_get_contents($root.'/.build/browser-fixture.json'),true);
    abort_unless(auth()->id()===$fixture['owner']&&in_array($type,['forge','paper','neoforge'],true)&&in_array($version,['1.21.1','26.1'],true),403);
    $server=App\Models\Server::where('uuid_short',$fixture['server'])->firstOrFail();
    IByte\MinecraftManager\Models\Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>strtoupper($type),'version'=>$version,'loader'=>$type]]);
    return ['ok'=>true];
});
Illuminate\Support\Facades\Route::middleware('web')->get('/__fixture/progress/{phase}', function (string $phase) use ($root) {
    abort_unless(in_array($phase,['download','verify','apply','done','blocked'],true),404);
    $fixture=json_decode(file_get_contents($root.'/.build/browser-fixture.json'),true);
    abort_unless(auth()->id()===$fixture['owner'],403);
    $server=App\Models\Server::where('uuid_short',$fixture['server'])->firstOrFail();
    $query=IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('actor_id',auth()->id());
    $operation=($phase==='blocked'?$query->latest():$query->whereNotNull('server_slot'))->firstOrFail();
    $plan=$operation->plan; $plan['progress']=['phase'=>$phase==='done'?'result':$phase,'files'=>5,'total'=>20,'fraction'=>.375,'file'=>'fixture-example.jar',
        'started_at'=>gmdate('c',time()-15),'phase_started_at'=>gmdate('c',time()-4),'elapsed_seconds'=>15,'phase_elapsed_seconds'=>4,'phase_seconds'=>['download'=>11,'verify'=>4]];
    $operation->update(['plan'=>$plan,'status'=>$phase==='done'?'succeeded':($phase==='apply'?'applying':($phase==='verify'?'verifying':'downloading')),'step'=>$phase==='done'?'installed':$phase,'server_slot'=>$phase==='done'?null:$server->id]);
    if($phase==='blocked'){$operation->update(['status'=>'needs-reconciliation','error_code'=>'installation_failed','updated_at'=>now()->subHours(11)]);}
    return ['ok'=>true];
});
Illuminate\Support\Facades\Route::middleware('web')->get('/__fixture/cache-state', fn () => [
    'mapping_hint' => (require plugin_path('ibyte-minecraft-manager', 'lang/en/messages.php'))['mapping_hint'],
    'mapping_feedback' => property_exists(IByte\MinecraftManager\Filament\Admin\MinecraftManager::class, 'mappedEgg'),
    'opcache' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
]);
Illuminate\Support\Facades\Route::middleware('web')->get('/__fixture/login/{role}', function (string $role) use ($root) {
    abort_unless(in_array($role, ['owner', 'admin'], true), 404);
    $fixture = json_decode(file_get_contents($root . '/.build/browser-fixture.json'), true, 64, JSON_THROW_ON_ERROR);
    // Actor switches must not retain AuthenticateSession's old password hash.
    Illuminate\Support\Facades\Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    Illuminate\Support\Facades\Auth::loginUsingId($fixture[$role]);
    request()->session()->regenerate();
    return redirect($role === 'admin' ? (request()->boolean('plugins') ? '/admin/plugins' : '/admin/minecraft-manager') : '/server/' . $fixture['server'] . '/minecraft-manager');
});
$app->handleRequest($request);
