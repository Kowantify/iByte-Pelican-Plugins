<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Cache::setDefaultDriver('array');
$owner=App\Models\User::factory()->create();$egg=App\Models\Egg::factory()->create();$server=App\Models\Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id]);
app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id'=>$egg->id,'enabled'=>true]);
app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled'=>true,'experimental'=>true,'providers'=>['modrinth']]);
$versions=array_map(fn($i)=>'1.21.'.$i,range(1,100));$loaders=['bukkit','spigot','paper','purpur','folia'];
$http=new FixtureTransport([
    'modrinth/v2/search'=>['hits'=>[['project_id'=>'voice','title'=>'Simple Voice Chat','description'=>'Voice chat','server_side'=>'required']],'total_hits'=>1],
    'modrinth/v2/project/voice/version'=>[
        ['id'=>'many','name'=>'Simple Voice Chat 2.6.24','version_type'=>'release','game_versions'=>$versions,'loaders'=>$loaders],
        ['id'=>'single','name'=>'Simple Voice Chat 2.6.23','version_type'=>'beta','game_versions'=>['1.21.1'],'loaders'=>['paper']],
    ],
]);$app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class,$http);
auth()->login($owner);Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));Filament\Facades\Filament::setTenant($server);
$page=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class)->set('experimental',true)->call('switchArea','packages')->call('clearFilters')->call('inspectProject','voice');
$page->assertSet('selectedRelease','many')->assertSee('100 versions')->assertSee('5 platforms')->assertSee('Supported Minecraft versions: '.implode(', ',$versions))->assertSee('Bukkit / Spigot / Paper / Purpur / Folia')->assertSee('imm-release-support');
$html=$page->html();preg_match('/<option value="many">(.*?)<\/option>/s',$html,$matches);
check(isset($matches[1])&&mb_strlen(html_entity_decode(trim(strip_tags($matches[1]))))<=110&&!str_contains($matches[1],'1.21.100'),'Option must stay bounded while the full version list is available below');
echo "PASS large support list uses a compact option and complete wrapping details\n";
$page->set('selectedRelease','single')->assertSee('Supported Minecraft versions: 1.21.1')->assertSee('Loaders / platforms: Paper');
echo "PASS changing release updates supported version/platform details\n";
file_put_contents($root.'/docs/evidence/release-layout-preview.26.json',json_encode(['passed'=>2,'versions'=>100,'platforms'=>5,'scope'=>'Native rendered Livewire modal with synthetic large support lists; no browser visual or install-engine rerun'],JSON_PRETTY_PRINT)."\n");
echo "RESULT 2 release layout checks passed.\n";
