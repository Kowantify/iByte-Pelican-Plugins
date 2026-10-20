<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Provider fixtures must never reuse cached browser or previous-run responses.
Illuminate\Support\Facades\Cache::setDefaultDriver('array');

use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Filament\Server\MinecraftManager as ServerPage;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Models\Setting;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Software\ProfileWizard;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Settings;
use Livewire\Livewire;

$passed = 0;
function catalogTest(string $name, callable $test): void
{
    global $passed;
    try { $test(); ++$passed; echo 'PASS ', $name, "\n"; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); }
}
$owner = User::factory()->create();
$egg = Egg::factory()->create(['docker_images' => ['Java 21' => 'native/image:java_21']]);
$server = Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'custom/current-image:latest']);
app(Settings::class)->save(['enabled' => true, 'experimental' => false, 'providers' => ['mcjars', 'modrinth', 'curseforge']]);
$profile = app(ProfileWizard::class)->save(['egg_id' => $egg->id, 'enabled' => true]);
$file = ['filename' => 'catalog.jar', 'size' => 4, 'primary' => true, 'hashes' => ['sha512' => hash('sha512', 'test')]];
$http = new FixtureTransport([
    'modrinth/v2/search' => ['hits' => [['project_id' => 'catalog', 'title' => 'Full Catalog Item', 'description' => 'Visible without egg metadata', 'server_side' => 'required']], 'total_hits' => 1],
    'curseforge/v1/mods/search' => ['data' => [['id' => 123, 'name' => 'CurseForge Catalog Item', 'summary' => 'Full catalog']], 'pagination' => ['totalCount' => 1]],
    'mcjars/api/v2/types' => ['types' => ['all' => ['VANILLA' => ['name' => 'Vanilla'], 'PAPER' => ['name' => 'Paper'], 'FABRIC' => ['name' => 'Fabric']]]],
    'mcjars/api/v3/builds/types/PAPER/versions' => ['versions'=>['page'=>1,'total'=>1,'data'=>[['id'=>'26.1','type'=>'RELEASE']]]],
    'mcjars/api/v3/builds/types/PAPER/versions/26.1' => ['builds'=>['page'=>1,'total'=>1,'data'=>[['uuid'=>'catalog-paper-build','name'=>'fixture build','experimental'=>false]]]],
    'modrinth/v2/project/catalog' => ['id' => 'catalog', 'project_type' => 'mod', 'server_side' => 'required'],
    'modrinth/v2/version/catalog-single' => ['id' => 'catalog-single', 'project_id' => 'catalog', 'name' => 'Single', 'version_number' => '1', 'version_type' => 'release',
        'game_versions' => ['26.1'], 'loaders' => ['fabric'], 'dependencies' => [], 'files' => [$file]],
    'modrinth/v2/version/catalog-multi' => ['id' => 'catalog-multi', 'project_id' => 'catalog', 'name' => 'Multi', 'version_number' => '2', 'version_type' => 'release',
        'game_versions' => ['26.1', '26.2'], 'loaders' => ['fabric', 'quilt'], 'dependencies' => [], 'files' => [$file]],
    'modrinth/v2/project/catalog-plugin' => ['id' => 'catalog-plugin', 'project_type' => 'plugin', 'server_side' => 'required'],
    'modrinth/v2/version/plugin-single' => ['id' => 'plugin-single', 'project_id' => 'catalog-plugin', 'name' => 'Plugin', 'version_number' => '1', 'version_type' => 'release',
        'game_versions' => ['26.1'], 'loaders' => ['paper'], 'dependencies' => [], 'files' => [$file]],
]);
$app->instance(JsonTransport::class, $http);
$catalog = app(Catalog::class);
foreach (['modrinth', 'curseforge'] as $provider) {
    foreach (['mod', 'plugin', 'modpack'] as $type) {
        foreach (['popular','downloads','updated'] as $sort) {
        $context = $catalog->context(['type' => $type,'sort'=>$sort]);
        $credential = $provider === 'curseforge' ? hash('sha256', app(Settings::class)->credential($provider) ?? '') : '';
        Illuminate\Support\Facades\Cache::forget('imm.catalog.v9.'.hash('sha256', json_encode([$provider, $context, $credential], JSON_THROW_ON_ERROR)));
        }
    }
}
catalogTest('egg-only mapping needs no software, image, loader or provider discovery', function () use ($profile, $owner, $server): void {
    check($profile->enabled && $profile->images === [] && $profile->loader === null && $profile->content_type === 'none');
    check(app(Access::class)->allowed($owner, $server));
    check(Access::runtime($server, $profile)['software'] === 'UNKNOWN');
});
auth()->login($owner); Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server);
$page = null;
catalogTest('mod catalog auto-loads with no hidden image, loader or version filter', function () use ($owner, $http, &$page): void {
    $page = Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea', 'packages')
        ->assertSet('error', null)->assertSet('gameVersion', '')->assertSet('loaderFilter', '')->assertSee('Full Catalog Item');
    $calls = array_values(array_filter($http->calls, fn ($call) => $call[1] === '/v2/search'));
    check(json_decode(end($calls)[2]['facets'], true) === [['project_type:mod']]);
});
catalogTest('plugins and modpacks browse independently of the mapped egg type', function () use (&$page, $http): void {
    $page->set('packageType', 'plugin')->assertSet('error', null)->assertSee('Full Catalog Item');
    $calls = array_values(array_filter($http->calls, fn ($call) => $call[1] === '/v2/search'));
    check(json_decode(end($calls)[2]['facets'], true) === [['project_type:plugin']]);
    $page->call('switchArea', 'packs')->assertSet('error', null)->assertSee('Full Catalog Item');
    $calls = array_values(array_filter($http->calls, fn ($call) => $call[1] === '/v2/search'));
    check(json_decode(end($calls)[2]['facets'], true) === [['project_type:modpack']]);
});
catalogTest('sorting resets pagination and sends the selected order to the provider', function () use (&$page,$http): void {
    $page->set('page',2)->set('sort','updated')->assertSet('page',1)->assertSet('error',null);
    $calls=array_values(array_filter($http->calls,fn ($c)=>$c[1]==='/v2/search'));
    check(end($calls)[2]['index']==='updated' && end($calls)[2]['offset']===0);
    $page->set('sort','popular')->assertSet('error',null);
});
catalogTest('CurseForge class catalogs have no implicit loader or game version', function () use (&$page, $http): void {
    $page->set('provider', 'curseforge')->assertSet('error', null)->assertSee('CurseForge Catalog Item');
    $page->call('switchArea', 'packages')->set('packageType', 'mod')->assertSet('error', null);
    $calls = array_values(array_filter($http->calls, fn ($call) => $call[0] === 'curseforge'));
    $query = end($calls)[2]; check($query['classId'] === 6 && !isset($query['modLoaderType']) && !isset($query['gameVersion']));
});
catalogTest('all software families auto-load without mapping to one family', function () use (&$page): void {
    $page->call('switchArea', 'software')->assertSet('error', null)->assertSee('Vanilla')->assertSee('Paper')->assertSee('Fabric');
    check(array_column($page->get('types'), 'id') === ['VANILLA', 'PAPER', 'FABRIC']);
});
catalogTest('native searchable software fields query the provider and retain exact selection validation', function () use (&$page,$http): void {
    $page->call('chooseSoftware','PAPER')->assertSet('error',null);
    $page->call('searchSoftwareVersions','26.1')->assertSet('error',null);
    $calls=array_values(array_filter($http->calls,fn ($c)=>str_ends_with($c[1],'/PAPER/versions')));
    check(end($calls)[2]['search']==='26.1');
    $page->set('selectedSoftwareVersion','26.1')->assertSet('softwareVersion','26.1');
    $page->call('searchSoftwareBuilds','fixture')->assertSet('error',null);
    $calls=array_values(array_filter($http->calls,fn ($c)=>str_ends_with($c[1],'/PAPER/versions/26.1')));
    check(end($calls)[2]['search']==='fixture');
});
$reviews = app(ReviewService::class);
catalogTest('mods and plugins both reach executable review on a visibility-only mapping', function () use ($reviews, $owner, $server): void {
    foreach ([['catalog', 'catalog-single', 'mod'], ['catalog-plugin', 'plugin-single', 'plugin']] as [$project, $release, $type]) {
        $op = $reviews->create($owner, $server, ['kind' => 'package', 'provider' => 'modrinth', 'project' => $project, 'release' => $release, 'type' => $type]);
        check($op->plan['executable'], json_encode($op->plan['blockers']));
        $reviews->queue($owner, $server, $op->id, true); $reviews->cancel($owner, $server, $op->id);
    }
});
catalogTest('ambiguous release can be browsed and becomes installable after user chooses target', function () use ($reviews, $owner, $server): void {
    $selection = ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'catalog', 'release' => 'catalog-multi', 'type' => 'mod'];
    $op = $reviews->create($owner, $server, $selection); check(!$op->plan['executable']);
    $op = $reviews->create($owner, $server, [...$selection, 'version' => '26.2', 'loader' => 'quilt']); check($op->plan['executable']);
});
catalogTest('direct Install creates one durable install without a review dialog',function()use($owner,$server,$reviews,$http){
    $http->responses['modrinth/v2/project/catalog/version']=[['id'=>'catalog-single','name'=>'Single','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']]];
    $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->call('inspectProject','catalog')->set('selectedRelease','catalog-single')->call('installSelectedRelease')->assertSet('error',null)->assertSet('projectOpen',false)->assertDontSee('Installation review');
    $op=IByte\MinecraftManager\Models\Operation::where('server_slot',$server->id)->firstOrFail();check($op->selection['intent']==='install'&&$op->status==='queued');$reviews->cancel($owner,$server,$op->id);
});
$queuePage=null;
catalogTest('Add to queue deduplicates a project and survives a fresh page mount',function()use($owner,$server,&$queuePage){
    $queuePage=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages');
    for($i=0;$i<2;$i++){$queuePage->call('inspectProject','catalog')->set('selectedRelease','catalog-single')->call('addSelectedRelease')->assertSet('error',null)->assertSet('projectOpen',false);}
    check(count($queuePage->get('installQueue'))===1&&!IByte\MinecraftManager\Models\Operation::where('server_slot',$server->id)->exists());
    $queuePage=Livewire::actingAs($owner)->test(ServerPage::class);check(count($queuePage->get('installQueue'))===1);$queuePage->assertSee('Install queue (1)');
});
catalogTest('forged release selections cannot install or replace a queued item',function()use(&$queuePage){
    $queuePage->call('switchArea','packages')->call('inspectProject','catalog')->set('selectedRelease','unlisted-release')->call('addSelectedRelease')->assertSet('error','invalid_selection')
        ->call('installSelectedRelease')->assertSet('error','invalid_selection');check(count($queuePage->get('installQueue'))===1);
});
catalogTest('pending queue is isolated by actor and server within the same session',function()use($owner,$server){
    $other=Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$server->egg_id]);Filament::setTenant($other);
    check(Livewire::actingAs($owner)->test(ServerPage::class)->get('installQueue')===[]);
    Filament::setTenant($server);$subuser=User::factory()->create();App\Models\Subuser::create(['user_id'=>$subuser->id,'server_id'=>$server->id,'permissions'=>['minecraft-manager.browse','minecraft-manager.plan']]);
    Livewire::actingAs($subuser)->test(ServerPage::class)->assertSet('installQueue',[])->call('clearQueue');
    check(count(Livewire::actingAs($owner)->test(ServerPage::class)->get('installQueue'))===1);
});
catalogTest('failed dispatch retains the pending queue while another install owns the slot',function()use($owner,$server,$reviews){
    $other=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'catalog-plugin','release'=>'plugin-single','type'=>'plugin']);$reviews->queue($owner,$server,$other->id,true);
    $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('installQueued')->assertSet('error','operation_in_progress');check(count($page->get('installQueue'))===1);$reviews->cancel($owner,$server,$other->id);
});
catalogTest('queue Install refreshes expired internal metadata without asking for review',function()use($owner,$server,$reviews){
    IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('status','planned')->update(['expires_at'=>now()->subMinute()]);
    Livewire::actingAs($owner)->test(ServerPage::class)->call('installQueued')->assertSet('error',null)->assertSet('installQueue',[])->assertDontSee('Installation review');
    $op=IByte\MinecraftManager\Models\Operation::where('server_slot',$server->id)->firstOrFail();check($op->status==='queued'&&$op->selection['intent']==='install');$reviews->cancel($owner,$server,$op->id);
});
catalogTest('modpacks also dispatch through one Install action without a review screen',function()use($owner,$server,$reviews,$http,$file){
    $previous=$http->responses['modrinth/v2/search'];
    try{
        $http->responses['modrinth/v2/search']=['hits'=>[['project_id'=>'direct-pack','title'=>'Direct pack','server_side'=>'required']],'total_hits'=>1];
        $http->responses['modrinth/v2/project/direct-pack']=['id'=>'direct-pack','project_type'=>'modpack','server_side'=>'required'];
        $http->responses['modrinth/v2/project/direct-pack/version']=[['id'=>'direct-pack-v1','name'=>'Pack 1','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']]];
        $http->responses['modrinth/v2/version/direct-pack-v1']=['id'=>'direct-pack-v1','project_id'=>'direct-pack','name'=>'Pack 1','version_number'=>'1','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric'],'files'=>[[...$file,'filename'=>'pack.mrpack']],'dependencies'=>[]];
        Illuminate\Support\Facades\Cache::flush();
        Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packs')->call('inspectProject','direct-pack')->set('selectedRelease','direct-pack-v1')->call('installSelectedRelease')->assertSet('error',null)->assertSet('projectOpen',false)->assertDontSee('Installation review');
        $op=IByte\MinecraftManager\Models\Operation::where('server_slot',$server->id)->firstOrFail();check($op->kind==='pack'&&$op->selection['intent']==='install');$reviews->cancel($owner,$server,$op->id);
    }finally{$http->responses['modrinth/v2/search']=$previous;Illuminate\Support\Facades\Cache::flush();}
});
catalogTest('tracked runtime supplies removable catalog defaults while review still checks compatibility', function () use ($owner, $server, $reviews): void {
    Setting::updateOrCreate(['key' => 'runtime-'.$server->id], ['value' => ['software' => 'FABRIC', 'loader' => 'fabric', 'version' => '26.1']]);
    Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea', 'packages')->assertSet('gameVersion', '26.1')->assertSet('loaderFilter', 'fabric')->assertSee('Full Catalog Item')
        ->call('clearFilters')->assertSet('gameVersion','')->assertSet('loaderFilter','')->call('switchArea','packs')->call('switchArea','packages')->assertSet('gameVersion','')->assertSet('loaderFilter','');
    $op = $reviews->create($owner, $server, ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'catalog-plugin', 'release' => 'plugin-single', 'type' => 'plugin', 'loader' => 'paper']);
    check(!$op->plan['executable'] && in_array('installed_runtime_mismatch', $op->plan['blockers'], true));
});
catalogTest('disabling the egg hides access regardless of server image', function () use ($profile, $owner, $server): void {
    $profile->update(['enabled' => false]); check(!app(Access::class)->allowed($owner, $server)); $profile->update(['enabled' => true]);
});
catalogTest('experimental browsing is separate from administrator install policy', function () use ($catalog, $http, $reviews, $owner, $server): void {
    check($catalog->context(['experimental' => true])['experimental']);
    $http->responses['modrinth/v2/version/catalog-beta'] = [...$http->responses['modrinth/v2/version/catalog-single'], 'id' => 'catalog-beta', 'version_type' => 'beta'];
    rejects(fn () => $reviews->create($owner, $server, ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'catalog', 'release' => 'catalog-beta', 'type' => 'mod', 'experimental' => true]), 'experimental_disabled');
});
catalogTest('native admin form enables an egg with only egg id and checkbox', function () use ($http): void {
    $admin = User::factory()->create(); $admin->assignRole(App\Models\Role::findOrCreate(App\Models\Role::ROOT_ADMIN, 'web'));
    $egg = Egg::factory()->create(['docker_images' => []]); $before = count($http->calls);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($admin)->test(IByte\MinecraftManager\Filament\Admin\MinecraftManager::class)
        ->set('mapping', ['egg_id' => $egg->id, 'enabled' => true])->call('saveMapping')->assertHasNoErrors()->assertSet('error', null)->assertSet('message', 'saved');
    check(Profile::where('egg_id', $egg->id)->where('enabled', true)->exists() && count($http->calls) === $before);
});
catalogTest('saved egg feedback shows matching server links and warns when global policy hides them', function (): void {
    $admin = User::factory()->create(); $admin->assignRole(App\Models\Role::findOrCreate(App\Models\Role::ROOT_ADMIN, 'web'));
    $egg = Egg::factory()->create(['name' => 'Visible saved egg']);
    $server = Server::factory()->create(['owner_id' => $admin->id, 'egg_id' => $egg->id, 'name' => 'Matching saved server']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = Livewire::actingAs($admin)->test(IByte\MinecraftManager\Filament\Admin\MinecraftManager::class)
        ->call('switchArea', 'settings')->set('mapping', ['egg_id' => $egg->id, 'enabled' => true])->call('saveMapping')
        ->assertSet('mappedEgg', 'Visible saved egg')->assertSee('Saved access for Visible saved egg')
        ->assertSee('Matching saved server')->assertSee('Open Minecraft Manager');
    app(Settings::class)->save(['enabled' => false]);
    $page->call('$refresh')->assertSee('disabled globally')->assertDontSee('Open Minecraft Manager');
    app(Settings::class)->save(['enabled' => true]);
});
Filament::setCurrentPanel(Filament::getPanel('server'));Filament::setTenant($server);auth()->login($owner);
$runtimePage=null;
catalogTest('Forge runtime opens mods with visible exact Minecraft and loader defaults',function()use($server,$owner,$http,&$runtimePage){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'FORGE','loader'=>'forge','version'=>'1.21.1']]);
    $runtimePage=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->assertSet('packageType','mod')->assertSet('gameVersion','1.21.1')->assertSet('loaderFilter','forge')->assertSee('Clear filters');
    $runtimePage->set('query','runtime-default-14-'.$server->id)->call('search');$calls=array_values(array_filter($http->calls,fn($call)=>$call[1]==='/v2/search'));
    check(json_decode(end($calls)[2]['facets'],true)===[['project_type:mod'],['versions:1.21.1'],['categories:forge']]);
});
catalogTest('cleared runtime filters stay cleared across provider changes sorting and tab navigation',function()use(&$runtimePage,$http){
    $runtimePage->call('clearFilter','version')->assertSet('gameVersion','')->assertSet('loaderFilter','forge')->set('provider','curseforge')->assertSet('gameVersion','');
    $calls=array_values(array_filter($http->calls,fn($call)=>$call[0]==='curseforge'));check(!isset(end($calls)[2]['gameVersion'])&&end($calls)[2]['modLoaderType']===1);
    $runtimePage->call('clearFilter','loader')->set('sort','updated')->set('provider','modrinth')->call('switchArea','packs')->assertSet('gameVersion','')->assertSet('loaderFilter','')
        ->call('switchArea','packages')->assertSet('gameVersion','')->assertSet('loaderFilter','')->call('useRuntimeFilters')->assertSet('gameVersion','1.21.1')->assertSet('loaderFilter','forge');
});
catalogTest('mod and plugin filter choices are remembered independently without applying Forge to plugins',function()use(&$runtimePage){
    $runtimePage->call('clearFilter','version')->set('packageType','plugin')->assertSet('gameVersion','1.21.1')->assertSet('loaderFilter','')
        ->call('clearFilters')->set('packageType','mod')->assertSet('gameVersion','')->assertSet('loaderFilter','forge')
        ->set('packageType','plugin')->assertSet('gameVersion','')->assertSet('loaderFilter','');
});
catalogTest('a newly installed runtime replaces old defaults when the package page is reopened',function()use(&$runtimePage,$server){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'NEOFORGE','loader'=>'neoforge','version'=>'1.21.4']]);
    $runtimePage->call('switchArea','overview')->call('switchArea','packages')->assertSet('packageType','mod')->assertSet('gameVersion','1.21.4')->assertSet('loaderFilter','neoforge');
});
catalogTest('Paper opens Plugins with Bukkit and Spigot releases included and consistent install targets',function()use($server,$owner,$http,$reviews,$file){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'PAPER','loader'=>'paper','version'=>'26.1']]);
    $versions=[];
    foreach(['bukkit','spigot','paper','folia'] as $loader){$id='compat-'.$loader;$versions[]=['id'=>$id,'name'=>$loader,'version_type'=>'release','game_versions'=>['26.1'],'loaders'=>[$loader]];
        $http->responses['modrinth/v2/version/'.$id]=['id'=>$id,'project_id'=>'catalog','name'=>$loader,'version_number'=>'1','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>[$loader],'files'=>[$file],'dependencies'=>[]];}
    $http->responses['modrinth/v2/project/catalog/version']=$versions;
    $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->assertSet('packageType','plugin')->assertSet('gameVersion','26.1')->assertSet('loaderFilter','paper')->assertSee('Paper / Spigot / Bukkit')
        ->call('inspectProject','catalog')->assertSet('error',null);
    check(array_column($page->get('releases'),'id')===['compat-bukkit','compat-spigot','compat-paper']);
    $ids=[];foreach(['bukkit','spigot','paper'] as $loader){
        $op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'catalog','release'=>'compat-'.$loader,'type'=>'plugin']);check($op->plan['executable']&&$op->selection['loader']==='paper');
        $project='batch-'.$loader;$id='batch-version-'.$loader;$http->responses['modrinth/v2/project/'.$project]=['id'=>$project,'project_type'=>'plugin','server_side'=>'required'];
        $http->responses['modrinth/v2/version/'.$id]=[...$http->responses['modrinth/v2/version/compat-'.$loader],'id'=>$id,'project_id'=>$project,'files'=>[[...$file,'filename'=>$loader.'-plugin.jar']]];
        $ids[]=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>$project,'release'=>$id,'type'=>'plugin'])->id;
    }
    check($reviews->createBatch($owner,$server,$ids)->plan['executable']);
});
catalogTest('Spigot and Folia reviews refuse unsupported platform releases',function()use($server,$owner,$reviews){
    foreach(['SPIGOT'=>['compat-paper',false],'FOLIA'=>['compat-bukkit',false],'BUKKIT'=>['compat-spigot',false]] as $type=>[$release,$expected]){
        Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>$type,'loader'=>strtolower($type),'version'=>'26.1']]);
        $op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'catalog','release'=>$release,'type'=>'plugin']);check($op->plan['executable']===$expected&&in_array('loader_unknown_or_incompatible',$op->plan['blockers'],true));
    }
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'FOLIA','loader'=>'folia','version'=>'26.1']]);
    $op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'catalog','release'=>'compat-folia','type'=>'plugin']);check($op->plan['executable']);
});
catalogTest('untracked servers derive default filters from the selected server-local egg variables',function()use($server,$owner){
    Setting::whereKey('runtime-'.$server->id)->delete();
    foreach(['SERVER_TYPE'=>'forge','VERSION'=>'1.21.1','BUILDS'=>'52.1.0'] as $key=>$value){App\Models\EggVariable::factory()->create(['egg_id'=>$server->egg_id,'env_variable'=>$key,'default_value'=>$value,'rules'=>'required|string']);}
    $runtime=Access::runtime($server,Profile::where('egg_id',$server->egg_id)->firstOrFail());check($runtime['source']==='configured'&&$runtime['version']==='1.21.1'&&$runtime['loader']==='forge');
    Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->assertSet('gameVersion','1.21.1')->assertSet('loaderFilter','forge');
});
catalogTest('external runtime variable changes supersede stale tracking and invalid latest values remain unknown',function()use($server,$owner){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'FORGE','loader'=>'forge','version'=>'1.21.1','environment'=>['SERVER_TYPE'=>'forge','VERSION'=>'1.21.1']]]);
    foreach(['SERVER_TYPE'=>'paper','VERSION'=>'1.21.4'] as $key=>$value){$variable=$server->egg->variables()->where('env_variable',$key)->firstOrFail();App\Models\ServerVariable::updateOrCreate(['server_id'=>$server->id,'variable_id'=>$variable->id],['variable_value'=>$value]);}
    $runtime=Access::runtime($server->fresh(),Profile::where('egg_id',$server->egg_id)->firstOrFail());check($runtime['source']==='configured'&&$runtime['software']==='PAPER');
    Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->assertSet('packageType','plugin')->assertSet('gameVersion','1.21.4')->assertSet('loaderFilter','paper');
    $variable=$server->egg->variables()->where('env_variable','VERSION')->firstOrFail();App\Models\ServerVariable::where('server_id',$server->id)->where('variable_id',$variable->id)->update(['variable_value'=>'latest']);
    check(Access::runtime($server->fresh(),Profile::where('egg_id',$server->egg_id)->firstOrFail())['software']==='UNKNOWN');
});
catalogTest('runtime variable defaults stay isolated between two servers sharing one egg',function()use($server,$owner){
    $other=Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$server->egg_id]);
    foreach([[$server,['SERVER_TYPE'=>'forge','VERSION'=>'1.21.1']],[$other,['SERVER_TYPE'=>'neoforge','VERSION'=>'26.3']]] as [$target,$values]){
        foreach($values as $key=>$value){$variable=$target->egg->variables()->where('env_variable',$key)->firstOrFail();App\Models\ServerVariable::updateOrCreate(['server_id'=>$target->id,'variable_id'=>$variable->id],['variable_value'=>$value]);}}
    $profile=Profile::where('egg_id',$server->egg_id)->firstOrFail();check(Access::runtime($server->fresh(),$profile)['version']==='1.21.1'&&Access::runtime($other,$profile)['version']==='26.3');
});
catalogTest('Folia filtering fails visibly on CurseForge and clearing it restores browsing',function()use($server,$owner){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'FOLIA','loader'=>'folia','version'=>'1.21.1']]);
    Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->set('provider','curseforge')->assertSet('error','provider_platform_filter_unavailable')
        ->call('clearFilter','loader')->assertSet('error',null)->assertSet('gameVersion','1.21.1')->assertSee('CurseForge Catalog Item');
});
catalogTest('proxy software versions never become Minecraft-version filters or block matching proxy plugins',function()use($server,$owner,$http,$file,$reviews){
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'VELOCITY','loader'=>'velocity','version'=>'3.4.0']]);
    $http->responses['modrinth/v2/version/proxy-plugin']=['id'=>'proxy-plugin','project_id'=>'catalog','name'=>'Proxy plugin','version_number'=>'1','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['velocity'],'files'=>[$file],'dependencies'=>[]];
    Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->assertSet('packageType','plugin')->assertSet('gameVersion','')->assertSet('loaderFilter','velocity');
    $op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'catalog','release'=>'proxy-plugin','type'=>'plugin']);
    check($op->plan['executable']&&$op->selection['version']==='26.1'&&$op->selection['loader']==='velocity');
});
Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server); auth()->login($owner);
$quickPage = null;
catalogTest('main actions resolve latest for installed runtime even after browsing other filters', function () use ($server, $owner, $http, &$quickPage): void {
    Setting::updateOrCreate(['key'=>'runtime-'.$server->id], ['value'=>['software'=>'FABRIC','loader'=>'fabric','version'=>'26.1']]);
    $http->responses['modrinth/v2/project/catalog/version'] = [
        ['id'=>'catalog-multi','name'=>'Newer other version','version_type'=>'release','game_versions'=>['26.2'],'loaders'=>['quilt']],
        ['id'=>'catalog-single','name'=>'Latest compatible','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']],
        ['id'=>'catalog-old','name'=>'Older compatible','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']],
    ];
    $quickPage = Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')
        ->set('gameVersion','26.2')->set('loaderFilter','quilt')->call('search')->call('queueProject','catalog')
        ->assertSet('error',null)->assertSet('selectedRelease','catalog-single')->assertSet('projectOpen',false);
    $items=$quickPage->get('installQueue'); check(count($items)===1 && reset($items)['selection']['version']==='26.1' && reset($items)['selection']['loader']==='fabric');
    $quickPage->call('clearQueue');
});
catalogTest('explicit release Search applies both filters resets pagination and clearing means all', function () use (&$quickPage, $http): void {
    $quickPage->call('inspectProject','catalog')->assertSet('selectedRelease','catalog-multi')->assertSet('installVersion','26.2')
        ->set('installVersion','26.1')->assertSet('selectedRelease','catalog-multi')->call('refreshReleases')->assertSet('selectedRelease','')->assertSet('releasePage',1)
        ->set('installLoader','fabric')->call('refreshReleases')->assertSet('selectedRelease','catalog-single');
    check(count($quickPage->get('releases'))===2);
    $quickPage->set('installVersion','')->set('installLoader','')->call('refreshReleases')->assertSet('error',null)->assertSet('selectedRelease','catalog-multi');
    check(count($quickPage->get('releases'))===3);
    $calls=array_values(array_filter($http->calls,fn($c)=>$c[1]==='/v2/project/catalog/version'));check(!isset(end($calls)[2]['game_versions']) && !isset(end($calls)[2]['loaders']));
    $quickPage->call('closeProject');
});
catalogTest('forged main project IDs cannot start an install or modify pending queue', function () use (&$quickPage): void {
    $quickPage->call('installProject','not-in-results')->assertSet('error','invalid_selection')
        ->call('queueProject','not-in-results')->assertSet('error','invalid_selection')->assertSet('installQueue',[]);
});
catalogTest('isolated row displays compatible release metadata and rechecks server access', function () use ($server, $owner): void {
    Livewire::actingAs($owner)->withoutLazyLoading()->test(IByte\MinecraftManager\Livewire\ProjectInstall::class,
        ['serverId'=>$server->id,'serverUuid'=>$server->uuid,'provider'=>'modrinth','project'=>'catalog','context'=>['type'=>'mod','version'=>'26.1','loader'=>'fabric']])
        ->assertSee('Latest compatible')->assertSee('Add to queue')->assertDontSee('Newer other version');
    Livewire::actingAs($owner)->withoutLazyLoading()->test(IByte\MinecraftManager\Livewire\ProjectInstall::class,
        ['serverId'=>$server->id,'serverUuid'=>$server->uuid,'provider'=>'modrinth','project'=>'catalog','context'=>['type'=>'mod'],'clientOnly'=>true])
        ->assertSee('Client only')->assertDontSee('Latest compatible')->assertDontSee('@elseif')->assertDontSee('$error')->assertDontSee('ibyte-minecraft-manager::messages');
});
catalogTest('CurseForge release pagination and latest selection reach stable files beyond a beta-only page', function (): void {
    $transport=new class implements JsonTransport {
        public int $calls=0;
        public function post(string $provider,string $path,array $body):array { throw new RuntimeException('Unexpected POST in catalog-only fixture'); }
    public function get(string $provider,string $path,array $query=[]):array {
            check($provider==='curseforge' && $path==='/v1/mods/123/files' && $query['gameVersion']==='1.21.1' && $query['modLoaderType']===1);
            ++$this->calls;
            $file=['id'=>456,'displayName'=>'Stable Forge release','releaseType'=>1,'gameVersions'=>['1.21.1','Forge']];
            return ['data'=>$query['index']===0?array_fill(0,50,[...$file,'releaseType'=>2]):[$file],'pagination'=>['totalCount'=>51]];
        }
    };
    $catalog=new Catalog($transport,app(Settings::class));$context=['type'=>'mod','version'=>'1.21.1','loader'=>'forge'];$more=false;
    check($catalog->releases('curseforge','123',$context,$more)===[] && $more);
    check($catalog->latestRelease('curseforge','123',$context)['id']==='456' && $transport->calls===3);
    check(count($catalog->releases('curseforge','123',[...$context,'release_page'=>2],$more))===1 && !$more);
});
catalogTest('rendered row branches never leak Blade source translation keys or unknown exception details', function () use ($server,$owner,$http): void {
    $path='modrinth/v2/project/catalog/version';$previous=$http->responses[$path];
    try{
        foreach([[],new IByte\MinecraftManager\Support\Failure('rate_limited'),new IByte\MinecraftManager\Support\Failure('unknown_private_code'),new RuntimeException('private-fixture-details')] as $response){
            Illuminate\Support\Facades\Cache::flush();$http->responses[$path]=$response;
            $page=Livewire::actingAs($owner)->withoutLazyLoading()->test(IByte\MinecraftManager\Livewire\ProjectInstall::class,
                ['serverId'=>$server->id,'serverUuid'=>$server->uuid,'provider'=>'modrinth','project'=>'catalog','context'=>['type'=>'mod','version'=>'26.1','loader'=>'fabric']])
                ->assertDontSee('@elseif')->assertDontSee('@endif')->assertDontSee('$error')->assertDontSee('ibyte-minecraft-manager::messages')->assertDontSee('private-fixture-details')->assertDontSee('unknown_private_code');
            $page->assertSee($response===[]?'No matching releases':__('ibyte-minecraft-manager::messages.errors.'.($response instanceof IByte\MinecraftManager\Support\Failure && $response->reason==='rate_limited'?'rate_limited':'request_failed')));
            if($response!==[]){$page->assertSee('Retry');}
        }
    }finally{$http->responses[$path]=$previous;Illuminate\Support\Facades\Cache::flush();}
});
catalogTest('unsaved release filters cannot install or queue a stale selection', function () use (&$quickPage,$server): void {
    $before=IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->count();
    $quickPage->call('inspectProject','catalog')->set('installVersion','1.20.1')->call('installSelectedRelease')->assertSet('error','release_filters_changed')
        ->call('addSelectedRelease')->assertSet('error','release_filters_changed');
    check(IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->count()===$before);
    $quickPage->set('installVersion','26.1')->set('installLoader','fabric')->call('refreshReleases')->assertSet('error',null)->assertSet('selectedRelease','catalog-single');
});
catalogTest('Load more releases appends options preserves selection and removes confusing release pages', function () use ($owner,$http): void {
    $path='modrinth/v2/project/catalog/version';$previous=$http->responses[$path];
    try{
        $http->responses[$path]=array_map(fn($n)=>['id'=>'history-'.$n,'name'=>'Release '.$n,'version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']],range(1,61));
        $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','catalog')->assertSet('releaseHasMore',true)->assertSee('Load more releases');
        check(count($page->get('releases'))===50);
        $page->call('loadMoreReleases')->assertSet('selectedRelease','history-1')->assertSet('releaseHasMore',false)->assertDontSee('Load more releases');
        check(count($page->get('releases'))===61 && count(array_unique(array_column($page->get('releases'),'id')))===61);
        $page->call('loadMoreReleases');check(count($page->get('releases'))===61);
    }finally{$http->responses[$path]=$previous;}
});
catalogTest('numbered catalog pages dispatch scroll only after a successful provider response', function () use ($owner,$http): void {
    $path='modrinth/v2/search';$previous=$http->responses[$path];
    try{
        Illuminate\Support\Facades\Cache::flush();$http->responses[$path]=[...$previous,'total_hits'=>200];
        $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->call('goToCatalogPage',2)->assertSet('page',2)->assertDispatched('imm-catalog-page-changed');
        check(str_contains($page->html(),'Go to page 10'));
        Illuminate\Support\Facades\Cache::flush();$http->responses[$path]=new IByte\MinecraftManager\Support\Failure('provider_unavailable');
        $page->call('goToCatalogPage',3)->assertSet('page',2)->assertSet('error','provider_unavailable')->assertNotDispatched('imm-catalog-page-changed');
    }finally{$http->responses[$path]=$previous;Illuminate\Support\Facades\Cache::flush();}
});
catalogTest('client-only release details keep browsing available and disable both installation buttons', function () use ($owner,$http): void {
    $path='modrinth/v2/search';$previous=$http->responses[$path];
    try{
        Illuminate\Support\Facades\Cache::flush();$http->responses[$path]=['hits'=>[['project_id'=>'catalog','title'=>'Client-only test mod','server_side'=>'unsupported']],'total_hits'=>1];
        $page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','catalog')->assertSee('Client-only mods cannot be installed on a server.')->assertSee('View');
        foreach(['installSelectedRelease','addSelectedRelease'] as $action){check(preg_match('/<button\b[^>]*wire:click="'.$action.'"[^>]*>/s',$page->html(),$matches)===1 && str_contains($matches[0],'disabled'),'Client-only dialog action was enabled');}
    }finally{$http->responses[$path]=$previous;Illuminate\Support\Facades\Cache::flush();}
});
catalogTest('Search applies explicit browser values even when the deferred model still holds old filters',function()use($owner,$http){
    $key='modrinth/v2/project/catalog/version';$old=$http->responses[$key];
    try{$http->responses[$key]=[
        ['id'=>'latest-wrong','name'=>'Latest wrong version','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']],
        ['id'=>'legacy-correct','name'=>'Legacy matching release','version_type'=>'release','game_versions'=>['1.12.2'],'loaders'=>['forge']],
    ];$page=Livewire::actingAs($owner)->test(ServerPage::class)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','catalog');
    $page->call('refreshReleases','1.12.2','forge')->assertSet('releaseVersion','1.12.2')->assertSet('releaseLoader','forge')->assertSet('selectedRelease','legacy-correct')->assertSee('[Release]')->assertSee('Minecraft 1.12.2');
    check(array_column($page->get('releases'),'id')===['legacy-correct']);
    $page->call('refreshReleases','1.12.2','fabric')->assertSet('selectedRelease','')->assertSee('No releases match the filters.');
    $page->call('refreshReleases','','')->assertSet('releaseVersion','')->assertSet('releaseLoader','');check(count($page->get('releases'))===2);
    }finally{$http->responses[$key]=$old;}
});
catalogTest('release options prefer Release then Beta then Alpha with newest order retained inside each channel',function()use($owner,$http){
    $key='modrinth/v2/project/catalog/version';$old=$http->responses[$key];
    try{$http->responses[$key]=array_map(fn($row)=>['id'=>$row[0],'name'=>$row[0],'version_type'=>$row[1],'game_versions'=>['26.1'],'loaders'=>['fabric']],[['alpha-new','alpha'],['beta-new','beta'],['release-new','release'],['release-old','release'],['beta-old','beta']]);
    $page=Livewire::actingAs($owner)->test(ServerPage::class)->set('experimental',true)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','catalog');
    check(array_column($page->get('releases'),'id')===['release-new','release-old','beta-new','beta-old','alpha-new']);$page->assertSet('selectedRelease','release-new')->assertSee('[Release]')->assertSee('[Beta]')->assertSee('[Alpha]');
    }finally{$http->responses[$key]=$old;}
});
catalogTest('CurseForge release filters reject mismatched provider rows and order channels',function()use($http){
    $key='curseforge/v1/mods/7000/files';$previous=$http->responses[$key]??null;
    try{$http->responses[$key]=['data'=>[
        ['id'=>1,'displayName'=>'Wrong version','releaseType'=>1,'gameVersions'=>['1.21.1','NeoForge']],
        ['id'=>2,'displayName'=>'Legacy alpha','releaseType'=>3,'gameVersions'=>['1.12.2','Forge']],
        ['id'=>3,'displayName'=>'Legacy beta','releaseType'=>2,'gameVersions'=>['1.12.2','Forge']],
        ['id'=>4,'displayName'=>'Legacy stable','releaseType'=>1,'gameVersions'=>['1.12.2','Forge']],
    ]];$context=app(IByte\MinecraftManager\Catalog\Catalog::class)->context(['type'=>'mod','version'=>'1.12.2','loader'=>'forge','experimental'=>true]);
    $rows=(new IByte\MinecraftManager\Catalog\CurseForge($http))->releases('7000',$context);check(array_column($rows,'id')===['4','3','2']);
    }finally{if($previous===null){unset($http->responses[$key]);}else{$http->responses[$key]=$previous;}}
});
catalogTest('release support labels use complete provider metadata instead of active filters',function()use($owner,$http){
    $key='modrinth/v2/project/catalog/version';$old=$http->responses[$key];
    try{$http->responses[$key]=[
        ['id'=>'multi-support','name'=>'Multi-version mod','version_type'=>'release','game_versions'=>['1.20.1','1.20.2','1.20.3','1.20.4','1.20.5','1.20.6'],'loaders'=>['forge','neoforge']],
        ['id'=>'unspecified','name'=>'Unknown support','version_type'=>'beta','game_versions'=>[],'loaders'=>[]],
    ];$page=Livewire::actingAs($owner)->test(ServerPage::class)->set('experimental',true)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','catalog');
    foreach([['1.20.1','forge'],['','']] as [$version,$loader]){
        $page->call('refreshReleases',$version,$loader)->assertSet('selectedRelease','multi-support')->assertSee('Minecraft 6 versions')->assertSee('Supported Minecraft versions: 1.20.1, 1.20.2, 1.20.3, 1.20.4, 1.20.5, 1.20.6')->assertSee('Forge / Neoforge')->assertSee('Supported Minecraft versions:')->assertSee('Loaders / platforms:');
    }
    $page->set('selectedRelease','unspecified')->assertSee('Minecraft not specified')->assertSee('Platform not specified')->assertSee('Supported Minecraft versions: Not specified');
    }finally{$http->responses[$key]=$old;}
});
file_put_contents($root.'/docs/evidence/catalog-access.json', json_encode(['passed' => $passed, 'scope' => 'Native tenant/image access, minimal admin mapping, real Livewire catalog actions, deterministic provider filters, review/queue compatibility; no node execution'], JSON_PRETTY_PRINT)."\n");
echo "RESULT $passed catalog access checks passed.\n";
