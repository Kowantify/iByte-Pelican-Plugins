<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(getenv('DB_DATABASE')!=='imm-live-catalog17.sqlite'){throw new RuntimeException('Use isolated catalog DB');}
require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Filament\Server\MinecraftManager;
use IByte\MinecraftManager\Models\Setting;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
Cache::setDefaultDriver('array');
$owner=App\Models\User::factory()->create();$egg=App\Models\Egg::factory()->create();$server=App\Models\Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id]);
app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id'=>$egg->id,'enabled'=>true]);
app(Settings::class)->save(['enabled'=>true,'experimental'=>true,'providers'=>['modrinth','curseforge']]);
auth()->login($owner);Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));Filament\Facades\Filament::setTenant($server);
$results=[];
foreach(['modrinth','curseforge'] as $provider){
    $replay=json_decode(file_get_contents($root.'/.build/catalog17-replay-'.$provider.'.json'),true,512,JSON_THROW_ON_ERROR);
    $transport=new class($replay['responses']) implements JsonTransport {
        public array $case=[];
        public function __construct(private array $responses){}
        public function post(string $provider,string $path,array $body):array { throw new RuntimeException('Unexpected POST in catalog-only fixture'); }
    public function get(string $provider,string $path,array $query=[]):array {
            $meta=$this->case['meta'];
            if(str_ends_with($path,'/search')){
                return $provider==='modrinth'?['hits'=>[['project_id'=>$this->case['project'],...$meta]],'total_hits'=>1]
                    :['data'=>[$meta],'pagination'=>['totalCount'=>1]];
            }
            $key=$provider.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
            return $this->responses[$key]??throw new RuntimeException('Missing public replay response');
        }
    };
    $app->instance(JsonTransport::class,$transport);
    foreach($replay['cases'] as $case){
        Cache::flush();$transport->case=$case;
        Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'NEOFORGE','loader'=>'neoforge','version'=>'26.3']]);
        try{
            $page=Livewire::actingAs($owner)->test(MinecraftManager::class)->set('provider',$provider)->call('switchArea','packages')
                ->assertSee($case['title'])->assertSee('View')->call('inspectProject',$case['project'])->assertSet('projectOpen',true)->assertSet('error',null);
            $page->set('installVersion','1.21.1')->set('installLoader','fabric')->call('refreshReleases')->assertSet('error',null)
                ->assertSet('releaseVersion','1.21.1')->assertSet('releaseLoader','fabric')->assertSet('releasePage',1)
                ->assertDontSee('@elseif')->assertDontSee('$error')->assertDontSee('ibyte-minecraft-manager::messages.errors.');
            foreach($page->get('releases') as $release){check(in_array('1.21.1',$release['game_versions'],true)&&in_array('fabric',$release['loaders'],true));}
            $page->set('installVersion','')->set('installLoader','')->call('refreshReleases')->assertSet('error',null)->assertSet('releaseVersion','')->assertSet('releaseLoader','');
            if($case['client_only']){
                foreach(['installSelectedRelease','addSelectedRelease'] as $action){check(preg_match('/<button\b[^>]*wire:click="'.$action.'"[^>]*>/s',$page->html(),$matches)===1&&str_contains($matches[0],'disabled'));}
            }
            $results[]=['provider'=>$provider,'project'=>$case['project'],'title'=>$case['title'],'status'=>'pass','filtered_and_unfiltered_dialog'=>true,'client_only'=>$case['client_only']];
            echo 'PASS real metadata dialog ',$provider,' ',$case['title'],"\n";
        }catch(Throwable $error){$results[]=['provider'=>$provider,'project'=>$case['project'],'status'=>'fail','reason'=>$error instanceof IByte\MinecraftManager\Support\Failure?$error->reason:'assertion_failed'];echo 'FAIL real metadata dialog ',$provider,' ',$case['title'],"\n";}
    }
}
file_put_contents($root.'/docs/evidence/real-catalog-dialogs17.json',json_encode(['scope'=>'Actual native Livewire page, View handler, Search handler and dialog output using replayed public metadata captured by live-catalog-matrix.php; no network, node or install in this test','tests'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$failed=count(array_filter($results,fn($r)=>$r['status']!=='pass'));echo 'RESULT ',count($results),' real metadata dialogs; ',$failed," failures.\n";exit($failed?1:0);
