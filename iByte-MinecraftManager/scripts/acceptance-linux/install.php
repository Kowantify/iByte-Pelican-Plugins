<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){throw new RuntimeException('Isolated Linux DB required');}
require '/opt/imm-panel/vendor/autoload.php';
$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use IByte\MinecraftManager\Support\{ArtifactDownloader,Settings,Failure};
use IByte\MinecraftManager\Operations\{ReviewService,CheckReview,NodeFiles};
use App\Models\{Server,User};
final class AcceptanceDownloader extends ArtifactDownloader {
    public function download(string $url,string $destination,?int $size,array $hashes=[]):array {
        $cache='/opt/imm/artifact-cache/'.hash('sha256',$url.json_encode($hashes));
        if(!is_dir(dirname($cache))){mkdir(dirname($cache),0700,true);}
        $algo=null;foreach(['sha512','sha256','sha1'] as $candidate){if(isset($hashes[$candidate])){$algo=$candidate;break;}}
        if($algo&&is_file($cache)&&filesize($cache)===$size&&hash_equals(strtolower($hashes[$algo]),hash_file($algo,$cache))){
            copy($cache,$destination);return ['size'=>$size,'sha256'=>hash_file('sha256',$cache),'source_integrity'=>$algo,'host'=>parse_url($url,PHP_URL_HOST)];
        }
        echo 'Download: ',basename(parse_url($url,PHP_URL_PATH)),"\n";flush();
        $result=parent::download($url,$destination,$size,$hashes);
        if($algo){copy($destination,$cache);}
        return $result;
    }
}
require __DIR__.'/transfer-probe.php'; TransferProbe::start($app);
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);
$name=$argv[1]??'cobblemon';$case=$fixture['cases'][$name]??throw new RuntimeException('Unknown case');
if(isset($argv[2])){if(!in_array($argv[2],['NEOFORGE','VANILLA'],true)||!in_array($argv[3]??'',['1.21.1','1.20.1'],true)){throw new RuntimeException('Unknown pre-pack test runtime');}$case['provider']='mcjars';$case['project']=$argv[2];$case['release']=$argv[3];}
$server=Server::findOrFail($case['id']);$owner=User::findOrFail($fixture['owner_id']);
$beforeEnvironment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server);$beforeStartup=$server->startup;
$settings=app(Settings::class);$previous=$settings->credential('curseforge');
$key=getenv('IMM_TEST_CF_KEY');$credentialSet=(bool)$key;if($key){$settings->replaceCredential('curseforge',$key);unset($key);putenv('IMM_TEST_CF_KEY');}
$result=['case'=>$name,'provider'=>$case['provider'],'project'=>$case['project'],'release'=>$case['release'],'node'=>'Official unmodified Wings v1.0.0-rc.1, private Docker daemon, Linux'];
try {
    $node=app(NodeFiles::class,['server'=>$server]);$node->assertStopped();
    $selection=['kind'=>'pack','provider'=>$case['provider'],'project'=>$case['project'],'release'=>$case['release'],'type'=>'modpack','experimental'=>true];
    if($case['provider']==='mcjars'){
        $http=app(IByte\MinecraftManager\Catalog\JsonTransport::class);
        $build=$http->get('mcjars','/api/v3/builds/types/'.$case['project'].'/versions/'.$case['release'],['per_page'=>1])['builds']['data'][0];
        $selection=['kind'=>'software','provider'=>'mcjars','project'=>$case['project'],'release'=>$build['uuid'],'version'=>$case['release'],'experimental'=>true];
    }
    $service=app(ReviewService::class);$op=$service->create($owner,$server,$selection);
    $result['operation']=$op->id;$result['started_at']=gmdate('c');
    echo 'Review: ',$op->id,"\n";
    $service->queue($owner,$server,$op->id,true);
    // Invoke the real queued handler with its real native HTTP adapters; no node/provider mocks.
    $app->call([new CheckReview($op->id,true),'handle']);$op->refresh();
    $result+=['status'=>$op->status,'step'=>$op->step,'error'=>$op->error_code,'failure'=>$op->plan['failure']??null,
        'skipped'=>$op->plan['skipped']??[],'source_resolutions'=>$op->plan['source_resolutions']??[],
        'files'=>count($op->plan['installed_files']??[]),'runtime'=>app(IByte\MinecraftManager\Support\Access::class)::runtime($server->fresh(),$settings->installed()?IByte\MinecraftManager\Models\Profile::where('egg_id',$server->egg_id)->firstOrFail():throw new RuntimeException('Missing schema'))];
    if($op->status==='succeeded'){
        $fresh=$server->fresh();$result['server_jar_sha256']=$node->hash('server.jar');$result['startup']=$fresh->startup;$result['image']=$fresh->image;
        $environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($fresh);$updates=IByte\MinecraftManager\Support\RuntimeVariables::updates($fresh,$result['runtime']);
        $result['runtime_variables_match']=!array_diff_assoc($updates,$environment);$result['runtime_environment']=$updates;
        $result['custom_variables_preserved']=!array_diff_assoc(array_intersect_key($beforeEnvironment,array_flip(['JVM_ARGS','MAXIMUM_MEMORY'])),$environment);
        $result['startup_preserved']=$fresh->startup===$beforeStartup;
        $result['previous_runtime_environment']=array_intersect_key($beforeEnvironment,array_flip(['SERVER_TYPE','VERSION','BUILDS','SERVER_JARFILE']));
        if(!$result['runtime_variables_match']||!$result['custom_variables_preserved']||!$result['startup_preserved']){throw new RuntimeException('Runtime variable or custom startup preservation failed');}
    }
    echo 'Result: ',json_encode($result,JSON_UNESCAPED_SLASHES),"\n";
} catch(Throwable $error){$result['fatal']=$error instanceof Failure?$error->reason:get_class($error).': '.$error->getMessage();echo 'Fatal: ',$result['fatal'],"\n";}
finally {if($credentialSet){$settings->replaceCredential('curseforge',$previous);}$result['transfer']=TransferProbe::result();$result['finished_at']=gmdate('c');file_put_contents('/opt/imm/'.$name.'-installation.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
exit(($result['status']??'')==='succeeded'&&!isset($result['fatal'])?0:1);
