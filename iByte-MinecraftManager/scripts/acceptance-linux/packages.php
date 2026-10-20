<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){throw new RuntimeException('Isolated Linux DB required');}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/transfer-probe.php'; TransferProbe::start($app);
use IByte\MinecraftManager\Support\{Settings,Access,Failure};
use IByte\MinecraftManager\Operations\{ReviewService,CheckReview,NodeFiles};
use IByte\MinecraftManager\Catalog\Catalog;
$name=$argv[1];$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$case=$fixture['cases'][$name];$server=App\Models\Server::findOrFail($case['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);
$catalog=app(Catalog::class);$settings=app(Settings::class);$previous=$settings->credential('curseforge');$key=getenv('IMM_TEST_CF_KEY');$credentialSet=(bool)$key;if($key){$settings->replaceCredential('curseforge',$key);unset($key);putenv('IMM_TEST_CF_KEY');}
$matrix=[
    'fabric1211'=>['modrinth','mod','1.21.1','fabric',['FerriteCore','Lithium']],
    'neoforge263'=>['modrinth','mod','26.3','neoforge',['Lithium','FerriteCore']],
    'forge1201'=>['modrinth','mod','1.20.1','forge',['FerriteCore','ModernFix']],
    'neoforge1211'=>['curseforge','mod','1.21.1','neoforge',['ModernFix']],
    'neoforge1211fast'=>['modrinth','mod','1.21.1','neoforge',['FerriteCore','ModernFix']],
    'paper1214'=>['modrinth','plugin','1.21.4','paper',['LuckPerms','WorldEdit']],
];
[$provider,$type,$version,$loader,$queries]=$matrix[$name];$result=['case'=>$name,'provider'=>$provider,'type'=>$type,'minecraft'=>$version,'loader'=>$loader,'started_at'=>gmdate('c'),'projects'=>[]];
try{
    $node=app(NodeFiles::class,['server'=>$server]);$node->assertStopped();
    $world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$before=is_file($world)?hash_file('sha256',$world):null;
    $reviews=[];$service=app(ReviewService::class);
    foreach($queries as $query){
        $context=['type'=>$type,'version'=>$version,'loader'=>$loader,'query'=>$query,'experimental'=>false];
        $hits=$catalog->search($provider,$context)['items'];$project=collect($hits)->first(fn($hit)=>strcasecmp($hit['title'],$query)===0)??throw new RuntimeException('Exact project title not found: '.$query);
        $releases=$catalog->releases($provider,$project['id'],$context);
        // Explicit test selection, never a product fallback: the latest WorldEdit
        // advertises 1.21.4 but requires Java 25; exercise its Java 21 release too.
        $chosen=$query==='WorldEdit'?collect($releases)->first(fn($release)=>preg_match('/\b7\.3\.10\b/',$release['title'])):($releases[0]??null);
        $release=$chosen['id']??throw new RuntimeException('No explicitly selected compatible release');
        $result['projects'][]=['title'=>$project['title'],'project'=>$project['id'],'release'=>$release,'release_title'=>$chosen['title'],'advertised_loaders'=>$chosen['loaders']??[]];
        $op=$service->create($owner,$server,['kind'=>'package','provider'=>$provider,'project'=>$project['id'],'release'=>$release,'type'=>$type,'version'=>$version,'loader'=>$loader]);$reviews[]=$op->id;
    }
    if(count($reviews)>1){$op=$service->createBatch($owner,$server,$reviews);}
    $result['operation']=$op->id;$service->queue($owner,$server,$op->id,true);$app->call([new CheckReview($op->id,true),'handle']);$op->refresh();
    $result+=['status'=>$op->status,'error'=>$op->error_code,'failure'=>$op->plan['failure']??null,'files'=>count($op->plan['installed_files']??[]),'world_unchanged'=>$before===(is_file($world)?hash_file('sha256',$world):null)];
    if($op->status==='succeeded'){
        foreach($op->plan['installed_files'] as $path=>$entry){if($entry['new']!==null&&!hash_equals($entry['new'],$node->hash($path))){throw new RuntimeException('Final file hash mismatch');}}
        $result['readback_verified']=true;
    }
}catch(Throwable $error){$result['fatal']=$error instanceof Failure?$error->reason:get_class($error).': '.$error->getMessage();}
finally{if($credentialSet){$settings->replaceCredential('curseforge',$previous);}$result['transfer']=TransferProbe::result();$result['finished_at']=gmdate('c');file_put_contents('/opt/imm/'.$name.'-packages.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result,JSON_UNESCAPED_SLASHES),"\n";}
exit(($result['status']??'')==='succeeded'&&!empty($result['readback_verified'])&&!empty($result['world_unchanged'])?0:1);
