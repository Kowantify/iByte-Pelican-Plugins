<?php
declare(strict_types=1);

// Real provider reads and optional bounded artifact verification. Disposable DB only.
$root=dirname(__DIR__);
if(getenv('DB_DATABASE')!=='imm-live-catalog17.sqlite'){throw new RuntimeException('Use the isolated catalog database');}
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Support\Settings;
use IByte\MinecraftManager\Support\SafeHttp;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Compatibility;
use IByte\MinecraftManager\Support\ArtifactDownloader;
use IByte\MinecraftManager\Livewire\ProjectInstall;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
Cache::setDefaultDriver('array');
$settings=app(Settings::class);$settings->save(['enabled'=>true,'experimental'=>true,'providers'=>['modrinth','curseforge','mcjars']]);
$key=getenv('IMM_TEST_CF_KEY');
if($key){$settings->replaceCredential('curseforge',$key);unset($key);putenv('IMM_TEST_CF_KEY');}
$http=new class(new SafeHttp($settings)) implements JsonTransport {
    public array $responses=[];public int $requests=0;
    public function __construct(private SafeHttp $http){}
    public function post(string $provider,string $path,array $body):array { ++$this->requests; return $this->http->post($provider,$path,$body); }
    public function get(string $provider,string $path,array $query=[]):array {
        usleep(300000);++$this->requests;
        $body=$this->http->get($provider,$path,$query);
        // Replay only catalog display fields, never download URLs, headers or credentials.
        if(str_ends_with($path,'/version') || str_ends_with($path,'/files')){
            $fields=array_flip(['id','name','version_type','game_versions','loaders','displayName','releaseType','gameVersions']);
            $safe=$provider==='modrinth'?array_map(fn($v)=>array_intersect_key($v,$fields),$body)
                : ['data'=>array_map(fn($v)=>array_intersect_key($v,$fields),$body['data']??[]),'pagination'=>$body['pagination']??[]];
            $this->responses[$provider.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986)]=$safe;
        }
        return $body;
    }
};
$app->instance(JsonTransport::class,$http);$catalog=new Catalog($http,$settings);$app->instance(Catalog::class,$catalog);
$owner=App\Models\User::factory()->create();$egg=App\Models\Egg::factory()->create();
$server=App\Models\Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id]);
app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id'=>$egg->id,'enabled'=>true]);
auth()->login($owner);Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));Filament\Facades\Filament::setTenant($server);
$sets=[['version'=>'26.3','loader'=>'neoforge'],['version'=>'1.21.1','loader'=>'fabric'],['version'=>'1.20.1','loader'=>'forge']];
$slugs=['sodium','iris','lithium','ferrite-core','modernfix','fabric-api','cloth-config','architectury-api','appleskin','krypton','spark','xaeros-minimap','journeymap','jei','jade','no-chat-reports','geckolib','create'];
$cfIds=['394468','360438','429235','790626','306612','348521','419699','248787','361579','263420','32274','238222','324717','388172','328085'];
$providers=in_array('--curseforge-only',$argv,true)?['curseforge']:['modrinth'];
$report=['version'=>'0.1.0-preview.17','scope'=>'Real provider catalog/release/exact metadata, production row rendering with real Livewire and optional checksum-verified artifacts; no live Wings or game startup','cases'=>[],'failures'=>[],'artifact_checks'=>[]];
$replay=['cases'=>[],'responses'=>[]];$count=0;
try {
    foreach($providers as $provider){
        if($provider==='curseforge'&&!$settings->credential('curseforge')){throw new RuntimeException('Credential missing from test process');}
        foreach($provider==='modrinth'?$slugs:$cfIds as $identity){
            try {
                $meta=$http->get($provider,$provider==='modrinth'?'/v2/project/'.$identity:'/v1/mods/'.$identity);
                if($provider==='curseforge'){$meta=$meta['data'];}
                $project=(string)$meta['id'];$title=$meta['title']??$meta['name'];$client=($meta['server_side']??'')==='unsupported';
                $case=['provider'=>$provider,'project'=>$project,'title'=>$title,'client_only'=>$client,'contexts'=>[]];
                $allContext=$catalog->context(['type'=>'mod','experimental'=>true]);$more=false;
                $all=$catalog->releases($provider,$project,$allContext,$more);
                $case['unfiltered_first_page']=count($all);$case['more_available']=$more;
                foreach($sets as $target){
                    $context=$catalog->context([...$target,'type'=>'mod','experimental'=>false]);
                    $latest=$catalog->latest($provider,$project,$context);
                    if($latest){check(in_array($target['version'],$latest['game_versions'],true),'Version filter returned another game version');check(Compatibility::accepts($target['loader'],$latest['loaders'],'mod'),'Loader filter returned incompatible release');}
                    $component=Livewire::actingAs($owner)->withoutLazyLoading()->test(ProjectInstall::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid,'provider'=>$provider,'project'=>$project,'context'=>$context,'clientOnly'=>$client]);
                    $html=$component->html();
                    check(!preg_match('/@(elseif|endif|else|if)\b|\$error|ibyte-minecraft-manager::messages/',$html),'Rendered template or translation key leaked');
                    $component->assertSee($client?'Client only':($latest['title']??'No matching releases'));
                    $case['contexts'][]=[...$target,'latest'=>$latest['id']??null,'title'=>$latest['title']??null,'row_rendered'=>true];
                    if($latest&&!$client){
                        $exact=$catalog->exact($provider,$project,$latest['id']);
                        check($exact['id']===$latest['id']&&$exact['project']===$project,'Exact identity changed');
                        if(in_array('--artifacts',$argv,true) && count($report['artifact_checks'])<6 && count(array_filter($report['artifact_checks'],fn($a)=>$a['loader']===$target['loader']))<2){
                            $artifact=$catalog->provider($provider)->artifact($project,$latest['id']);
                            if(($artifact['size']??PHP_INT_MAX)<=25165824){
                                $destination=$root.'/.build/live-catalog17-'.hash('sha256',$provider.$latest['id']).'.jar';
                                $verified=app(ArtifactDownloader::class)->download($artifact['url'],$destination,$artifact['size'],$artifact['hashes']);
                                $zip=new ZipArchive;check($zip->open($destination,ZipArchive::RDONLY)===true,'Downloaded artifact is not a JAR');$zip->close();
                                $report['artifact_checks'][]=['provider'=>$provider,'project'=>$project,'release'=>$latest['id'],...$target,'size'=>$verified['size'],'sha256'=>$verified['sha256'],'publisher_hash_checked'=>true];
                            }
                        }
                    }
                }
                $report['cases'][]=$case;
                $replay['cases'][]=['provider'=>$provider,'project'=>$project,'title'=>$title,'client_only'=>$client,'meta'=>array_intersect_key($meta,array_flip(['id','title','name','description','summary','slug','server_side','project_type','icon_url','logo','downloads','downloadCount','author','authors','updated','dateModified']))];
                ++$count;echo 'PASS ',$provider,' ',$title,': all releases and 3 version/loader contexts',"\n";
            }catch(Throwable $error){$code=$error instanceof Failure?$error->reason:'assertion_failed';$report['failures'][]=['provider'=>$provider,'project'=>$identity,'code'=>$code];echo 'FAIL ',$provider,' ',$identity,': ',$code,"\n";}
        }
    }
}finally{
    $settings->replaceCredential('curseforge',null);putenv('IMM_TEST_CF_KEY');
    $report['credential_cleared']=$settings->credential('curseforge')===null;
    $report['requests']=$http->requests;$report['recorded_at']=gmdate('c');
    $suffix=$providers[0];$replay['responses']=$http->responses;
    file_put_contents($root.'/docs/evidence/live-catalog17-'.$suffix.'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($root.'/.build/catalog17-replay-'.$suffix.'.json',json_encode($replay,JSON_UNESCAPED_SLASHES)."\n");
}
echo 'RESULT ',$count,' real projects; ',count($report['failures'])," failures.\n";exit($report['failures']?1:0);
