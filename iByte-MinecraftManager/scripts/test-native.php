<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$loader = require $root . '/.research/panel/vendor/autoload.php';
require $root . '/plugin/tests/bootstrap.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PluginStatus;
use App\Models\Egg;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Services\Helpers\PluginService;
use Filament\Facades\Filament;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Filament\Admin\MinecraftManager as AdminPage;
use IByte\MinecraftManager\Filament\Server\MinecraftManager as ServerPage;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Models\Setting;
use IByte\MinecraftManager\Operations\CheckReview;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Providers\MinecraftManagerProvider;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\SchemaInstaller;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

$passed = 0;
function nativeTest(string $name, callable $test): void
{
    global $passed;
    try { $test(); $passed++; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1); }
}

// Optional native asset-build calls are mocked unavailable. This plugin supplies
// no assets and does not need a rebuild. Import/extraction/migrations are real.
Process::fake(['*' => Process::result(errorOutput: 'Optional build unavailable in fixture', exitCode: 127)]);
$service = app(PluginService::class);
$version = json_decode(file_get_contents($root.'/plugin/plugin.json'), true, 64, JSON_THROW_ON_ERROR)['version'];
$candidate = $root.'/.build/ibyte-minecraft-manager-v'.$version.'.zip';
nativeTest('native ZIP import and plugin installation', function () use ($service, $candidate, $loader): void {
    $id = $service->downloadPluginFromFile(new UploadedFile($candidate, basename($candidate), 'application/zip', null, true));
    check($id === 'ibyte-minecraft-manager');
    // No manual namespace registration before running native migrations.
    Plugin::refreshRows();
    $plugin = Plugin::findOrFail($id);
    $service->installPlugin($plugin);
    Plugin::refreshRows();
    check(Plugin::findOrFail($id)->status === PluginStatus::Enabled);
    check(Schema::hasTable('imm_operations') && Schema::hasTable('imm_profiles'));
});
$app->register(MinecraftManagerProvider::class);
$settings = app(Settings::class);
nativeTest('encrypted credential storage set replace remove without config cache', function () use ($settings): void {
    $settings->replaceCredential('curseforge', 'fixture-private-value-A');
    check(!str_contains(DB::table('imm_settings')->where('key', 'credentials')->value('secret'), 'fixture-private-value-A'));
    check(!str_contains(Setting::find('credentials')->toJson(), 'fixture-private-value-A'));
    check($settings->credential('curseforge') === 'fixture-private-value-A');
    $settings->replaceCredential('curseforge', 'fixture-private-value-B');
    check($settings->credential('curseforge') === 'fixture-private-value-B');
    $settings->replaceCredential('curseforge', null);
    check($settings->credential('curseforge') === null);
});
$owner = User::factory()->create(); $outsider = User::factory()->create(); $subuser = User::factory()->create();
$admin = User::factory()->create(); $admin->assignRole(Role::findOrCreate(Role::ROOT_ADMIN, 'web'));
$egg = Egg::factory()->create(['docker_images' => ['Test Java' => 'approved/image:test']]);
$server = Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'approved/image:test', 'startup' => 'java -jar server.jar']);
$other = Server::factory()->create(['owner_id' => $outsider->id]);
$profile = Profile::updateOrCreate(['egg_id' => $egg->id], ['name' => 'Fixture profile', 'software' => 'FABRIC', 'loader' => 'fabric', 'content_type' => 'mod',
    'images' => ['approved/image:test'], 'jar_path' => 'server.jar', 'enabled' => true]);
$settings->save(['enabled' => true, 'experimental' => false, 'providers' => ['mcjars', 'modrinth', 'curseforge']]);
$access = app(Access::class);
nativeTest('native owner permissions, tenant isolation and unknown egg hidden', function () use ($access, $owner, $outsider, $server, $other): void {
    check($access->allowed($owner, $server)); check($access->allowed($owner, $server, 'plan'));
    check(!$access->allowed($outsider, $server)); check(!$access->allowed($outsider, $other));
});
$sub = Subuser::create(['server_id' => $server->id, 'user_id' => $subuser->id, 'permissions' => ['minecraft-manager.browse']]);
nativeTest('native subuser browse and modification permissions separated', function () use ($access, $subuser, $server): void {
    check($access->allowed($subuser, $server)); check(!$access->allowed($subuser, $server, 'plan')); check(!$access->allowed($subuser, $server, 'software'));
});
nativeTest('global disable denies access but image changes do not hide an enabled egg', function () use ($settings, $access, $owner, $server): void {
    $settings->save(['enabled' => false]); check(!$access->allowed($owner, $server)); $settings->save(['enabled' => true]);
    $server->image = 'unapproved/image:test'; check($access->allowed($owner, $server)); $server->image = 'approved/image:test';
});

$http = new FixtureTransport([
    'modrinth/v2/project/project' => ['id' => 'project', 'project_type' => 'mod', 'server_side' => 'required'],
    'modrinth/v2/version/version' => ['id' => 'version', 'project_id' => 'project', 'version_number' => '1.0', 'name' => '<script>bad()</script>Fixture',
        'version_type' => 'release', 'game_versions' => ['26.1'], 'loaders' => ['fabric'], 'dependencies' => [],
        'files' => [['filename' => 'fixture.jar', 'size' => 4, 'primary' => true, 'hashes' => ['sha512' => hash('sha512', 'test')]]]],
]);
$app->instance(JsonTransport::class, $http);
$selection = ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'project', 'release' => 'version', 'version' => '26.1', 'type' => 'mod'];
$reviews = app(ReviewService::class);
$operation = $reviews->create($owner, $server, $selection);
nativeTest('double-click review is idempotent, exact and contains no URL authority', function () use ($reviews, $owner, $server, $selection, $operation): void {
    check($reviews->create($owner, $server, $selection)->id === $operation->id);
    check($operation->plan['executable'] && $operation->plan['changes'] === []);
    check(!str_contains(json_encode($operation->plan), 'https://'));
});
nativeTest('cross-tenant operation ID cannot be forged', function () use ($reviews, $outsider, $other, $operation): void {
    try { $reviews->operation($outsider, $other, $operation->id); } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 403); return; }
    throw new RuntimeException('Expected cross-tenant denial');
});
nativeTest('operation scoping denies a different eligible tenant', function () use ($reviews, $owner, $egg, $operation): void {
    $eligible = Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'approved/image:test']);
    try { $reviews->operation($owner, $eligible, $operation->id); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException) { return; }
    throw new RuntimeException('Expected scoped operation lookup rejection');
});
nativeTest('queued review consumes one durable slot and rejects duplicate dispatch', function () use ($reviews, $owner, $server, $operation): void {
    $reviews->queue($owner, $server, $operation->id);
    check($operation->fresh()->server_slot === $server->id && $operation->fresh()->status === 'queued');
    rejects(fn () => $reviews->queue($owner, $server, $operation->id), 'plan_expired_or_used');
});
nativeTest('metadata readiness succeeds without mutation, duplicate delivery is inert', function () use ($app, $operation, $server): void {
    $before = $server->fresh()->getAttributes();
    $app->call([new CheckReview($operation->id), 'handle']);
    check($operation->fresh()->status === 'succeeded' && $operation->fresh()->server_slot === null);
    check($operation->fresh()->step === 'metadata-checked');
    $count = count(app(JsonTransport::class)->calls);
    $app->call([new CheckReview($operation->id), 'handle']);
    check(count(app(JsonTransport::class)->calls) === $count);
    check($server->fresh()->getAttributes() === $before);
});
nativeTest('acknowledgement persists after repeated job delivery', function () use ($app, $reviews, $owner, $server, $operation): void {
    $reviews->acknowledge($owner, $server, $operation->id); $ack = $operation->fresh()->acknowledged_at->toISOString();
    $app->call([new CheckReview($operation->id), 'handle']);
    check($operation->fresh()->acknowledged_at->toISOString() === $ack);
});
nativeTest('queued permission revocation is enforced', function () use ($app, $reviews, $subuser, $sub, $server, $selection): void {
    $sub->update(['permissions' => ['minecraft-manager.browse', 'minecraft-manager.plan']]);
    $op = $reviews->create($subuser, $server, $selection); $reviews->queue($subuser, $server, $op->id);
    $sub->update(['permissions' => []]);
    $app->call([new CheckReview($op->id), 'handle']);
    check($op->fresh()->error_code === 'permission_denied');
});
nativeTest('source node movement invalidates original review', function () use ($app, $reviews, $owner, $server, $selection): void {
    $s = $selection; $s['version'] = '26.2'; $op = $reviews->create($owner, $server, $s); $reviews->queue($owner, $server, $op->id);
    $original = $op->node_id; $op->update(['node_id' => $original + 100]);
    $app->call([new CheckReview($op->id), 'handle']); check($op->fresh()->error_code === 'source_identity_or_plugin_changed');
});
nativeTest('expired and tampered plans fail before provider work', function () use ($app, $reviews, $owner, $server, $selection): void {
    $s = $selection; $s['version'] = 'expired'; $op = $reviews->create($owner, $server, $s);
    $op->update(['expires_at' => now()->subMinute()]);
    rejects(fn () => $reviews->queue($owner, $server, $op->id), 'plan_expired_or_used');
    $s['version'] = 'tampered'; $op = $reviews->create($owner, $server, $s); $reviews->queue($owner, $server, $op->id);
    $plan = $op->plan; $plan['executable'] = !$plan['executable']; $op->update(['plan' => $plan]);
    $app->call([new CheckReview($op->id), 'handle']); check($op->fresh()->error_code === 'stale_plan');
});

nativeTest('Blade templates compile using native component registry', function (): void {
    foreach (glob(dirname(__DIR__) . '/plugin/resources/views/*.blade.php') as $file) {
        $compiled = app('blade.compiler')->compileString(file_get_contents($file)); check($compiled !== '');
        token_get_all($compiled, TOKEN_PARSE);
    }
});
nativeTest('reproducible ZIP mtimes cannot retain a stale plugin template', function (): void {
    $source = plugin_path('ibyte-minecraft-manager', 'resources', 'views') . '/server.blade.php';
    $compiler = app('blade.compiler');
    $original = file_get_contents($source);
    try {
        file_put_contents($source, $original . "\n<!-- fixture-update-proof -->\n"); touch($source, 315532800);
        IByte\MinecraftManager\Support\ViewCache::refresh($compiler);
        check(str_contains(file_get_contents($compiler->getCompiledPath($source)), 'fixture-update-proof'));
    } finally {
        file_put_contents($source, $original); touch($source, 315532800);
        IByte\MinecraftManager\Support\ViewCache::refresh($compiler);
    }
});
nativeTest('Livewire server page renders and switches internal areas', function () use ($owner, $server): void {
    auth()->login($owner); Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server);
    Livewire::actingAs($owner)->test(ServerPage::class)->assertSee('Minecraft Manager')->call('switchArea', 'packages')->assertSee('Mods & Plugins');
});
nativeTest('queued installation labels cancellation correctly and explains a delayed scheduler', function () use ($owner, $server, $operation): void {
    $queued = $operation->replicate();
    $selection = $queued->selection; $selection['intent'] = 'install';
    $queued->forceFill(['id' => (string) Illuminate\Support\Str::uuid(), 'idempotency' => hash('sha256', random_bytes(32)),
        'status' => 'queued', 'step' => 'queued', 'selection' => $selection, 'server_slot' => $server->id,
        'updated_at' => now()->subMinutes(3)])->save();
    try {
        Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server);
        Livewire::actingAs($owner)->test(ServerPage::class)->assertDontSee('wire:click="switchArea(\'activity\')"', false);
        Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid,'admin'=>false])
            ->assertSee('Cancel install')->assertSee('Processing has not started.')
            ->assertSee('Waiting for the install worker.');
        check(ServerPage::getNavigationSort() === 98);
    } finally { $queued->delete(); }
});
nativeTest('sidebar follows Subdomains even when another plugin assigns a later position', function (): void {
    $previous = Filament::getCurrentPanel();
    $panel = \Filament\Panel::make()->id('server')->navigationItems([
        \Filament\Navigation\NavigationItem::make('Subdomains')->sort(150),
    ]);
    try { Filament::setCurrentPanel($panel); check(ServerPage::getNavigationSort() === 151); }
    finally { Filament::setCurrentPanel($previous); }
});
nativeTest('independent Activity feed reauthorizes tenants and safely cancels owned queued work', function () use ($owner,$outsider,$server,$operation): void {
    $args=['serverId'=>$server->id,'serverUuid'=>$server->uuid,'admin'=>false];
    Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server);
    Livewire::actingAs($outsider)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,$args)->assertForbidden();
    Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['admin'=>true])->assertForbidden();
    $queued=$operation->replicate(); $selection=$queued->selection; $selection['intent']='install';
    $queued->forceFill(['id'=>(string)Illuminate\Support\Str::uuid(),'idempotency'=>hash('sha256',random_bytes(32)),
        'status'=>'queued','selection'=>$selection,'server_slot'=>$server->id])->save();
    try {
        Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,$args)
            ->assertSee('Cancel install')->call('cancelReview',$queued->id)->assertSee('Cancelled');
        check($queued->fresh()->status==='cancelled');
    } finally { $queued->delete(); }
});
nativeTest('active progress stays visible while more than thirty newer releases are reviewed', function () use ($owner,$server,$operation): void {
    $ids=[];
    try {
        for($i=0;$i<32;$i++){
            $copy=$operation->replicate();$plan=$copy->plan;
            if($i===0){$plan['release']['title']='Active long installation';$plan['progress']=['phase'=>'download','files'=>1,'total'=>4,'fraction'=>.375,'file'=>'example.jar'];}
            $copy->forceFill(['id'=>(string)Illuminate\Support\Str::uuid(),'idempotency'=>hash('sha256',random_bytes(32)),
                'status'=>$i===0?'downloading':'planned','server_slot'=>$i===0?$server->id:null,'created_at'=>$i===0?now()->subHour():now(),'plan'=>$plan])->save();$ids[]=$copy->id;
        }
        Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid,'admin'=>false])
            ->assertSee('Active long installation')->assertSee('38%');
    } finally { Operation::whereIn('id',$ids)->delete(); }
});
nativeTest('progress polling projects display fields without loading a large recovery journal', function () use ($owner,$server,$operation): void {
    $copy=$operation->replicate();$plan=$copy->plan;$plan['release']['title']='Large journal install';
    $plan['progress']=['phase'=>'download','files'=>1,'total'=>4,'fraction'=>.375,'file'=>'example.jar'];
    $plan['journal']=['private-recovery-data'=>str_repeat('x',1048576)];
    $copy->forceFill(['id'=>(string)Illuminate\Support\Str::uuid(),'idempotency'=>hash('sha256',random_bytes(32)),
        'status'=>'downloading','server_slot'=>$server->id,'plan'=>$plan])->save();
    try {
        $component=Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid,'admin'=>false])
            ->assertSee('Large journal install')->assertSee('38%');
        $row=$component->instance()->render()->getData()['operations']->firstWhere('id',$copy->id);
        check(!isset($row->plan['journal'])&&strlen(json_encode($row->plan))<8192);
        check(strlen(Operation::findOrFail($copy->id)->plan['journal']['private-recovery-data'])===1048576);
        foreach (['mysql','mariadb','pgsql'] as $driver) {
            config(['database.connections.imm-feed-'.$driver=>['driver'=>$driver,'database'=>'compile-only','host'=>'127.0.0.1','port'=>9]]);
            $query=(new Operation)->setConnection('imm-feed-'.$driver)->newQuery()->forFeed()->toSql();
            check(!str_contains($query,'select *') && str_contains($query,'feed_progress'));
            if($driver==='pgsql'){check(str_contains($query,'CAST("plan" AS JSONB)')&&str_contains($query,'CAST("selection" AS JSONB)'));}
        }
    } finally {$copy->delete();}
});
nativeTest('Livewire admin configuration denies ordinary owners', function () use ($owner): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($owner)->test(AdminPage::class)->assertForbidden();
});
nativeTest('Livewire admin renders settings without a credential read-back', function () use ($admin): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($admin)->test(AdminPage::class)->call('switchArea', 'settings')->assertSee('CurseForge API access')->assertSet('apiKey', '');
});
nativeTest('Livewire root admin credential input is cleared after save', function () use ($admin, $settings): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($admin)->test(AdminPage::class)->set('apiKey', 'fixture-browser-private-value')->call('replaceKey')
        ->assertSet('apiKey', '')->assertDontSee('fixture-browser-private-value');
    check($settings->credential('curseforge') === 'fixture-browser-private-value'); $settings->replaceCredential('curseforge', null);
});
nativeTest('additive missing-schema repair retains profiles and history', function () use ($profile, $operation): void {
    Schema::drop('imm_inventory'); SchemaInstaller::install(); SchemaInstaller::install();
    check(Schema::hasTable('imm_inventory') && Profile::find($profile->id) !== null && Operation::find($operation->id) !== null);
});
nativeTest('native reimport and reinstall retains settings and operation history', function () use ($service, $candidate, $profile, $operation): void {
    $service->downloadPluginFromFile(new UploadedFile($candidate, basename($candidate), 'application/zip', null, true), 'ibyte-minecraft-manager');
    Plugin::refreshRows(); $service->installPlugin(Plugin::findOrFail('ibyte-minecraft-manager'));
    check(Profile::find($profile->id) !== null && Operation::find($operation->id) !== null);
});
nativeTest('native disable and uninstall retain recovery metadata', function () use ($service, $operation): void {
    $plugin = Plugin::findOrFail('ibyte-minecraft-manager');
    $service->disablePlugin($plugin); Plugin::refreshRows(); check(Plugin::findOrFail($plugin->id)->status === PluginStatus::Disabled);
    $service->uninstallPlugin(Plugin::findOrFail($plugin->id));
    check(Operation::find($operation->id) !== null && Schema::hasTable('imm_settings'));
    Plugin::refreshRows(); $service->installPlugin(Plugin::findOrFail($plugin->id));
});
nativeTest('prepare a real database queue job for fresh-worker revocation check', function () use ($reviews, $subuser, $sub, $server, $selection, $root): void {
    $sub->update(['permissions' => ['minecraft-manager.browse', 'minecraft-manager.plan']]);
    $s = $selection; $s['version'] = 'fresh-worker'; $op = $reviews->create($subuser, $server, $s); $reviews->queue($subuser, $server, $op->id);
    $sub->update(['permissions' => []]);
    file_put_contents($root . '/.build/queue-probe.json', json_encode(['operation' => $op->id], JSON_THROW_ON_ERROR));
    check($op->fresh()->status === 'queued' && DB::table('jobs')->count() > 0);
});
echo "RESULT $passed native tests passed. SQLite/Windows; optional native asset build mocked; provider responses deterministic; no Wings/game container.\n";
