<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/transfer-probe.php';TransferProbe::start($app);
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$caseName=$argv[1]??'forge1201';$server=App\Models\Server::findOrFail($fixture['cases'][$caseName]['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);
$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);$node->assertStopped();
$inventory=Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->get()->filter(fn($row)=>(json_decode($row->metadata,true)['owner']['kind']??'')==='package');
if(count($inventory)!==2){throw new RuntimeException('Expected two test mods');}
$before=[];$ids=[];$reviews=app(IByte\MinecraftManager\Operations\ReviewService::class);
$runtime=IByte\MinecraftManager\Support\Access::runtime($server,IByte\MinecraftManager\Models\Profile::where('egg_id',$server->egg_id)->firstOrFail());
foreach($inventory as $row){$before[$row->path]=$node->hash($row->path);$ownerMetadata=json_decode($row->metadata,true)['owner'];$review=$reviews->create($owner,$server,['kind'=>'package','provider'=>$ownerMetadata['provider'],'project'=>$ownerMetadata['project'],'release'=>$ownerMetadata['release'],'version'=>$runtime['version'],'loader'=>$runtime['loader'],'type'=>'mod']);$ids[]=$review->id;}
$world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$worldHash=hash_file('sha256',$world);$op=$reviews->createBatch($owner,$server,$ids);$fault=false;$promotions=0;
Illuminate\Support\Facades\Http::globalRequestMiddleware(static function(Psr\Http\Message\RequestInterface $request)use($server,$op,&$fault,&$promotions){
    if($request->getMethod()==='PUT'&&str_ends_with($request->getUri()->getPath(),'/files/rename')&&$op->fresh()->status==='applying'){
        $body=json_decode((string)$request->getBody(),true);
        foreach($body['files']??[] as $move){
            if(str_starts_with($move['from'],'mods/.download-'.$op->id.'-')&&!str_contains($move['to'],'.download-')){
                ++$promotions;
                if($promotions===2){
                    $path='/opt/imm/volumes/'.$server->uuid.'/'.IByte\MinecraftManager\Support\Input::nodePath($move['from']);
                    if(!is_file($path)||is_link($path)){throw new RuntimeException('Unsafe test fault target');}
                    unlink($path);$fault=true;
                }
            }
        }
    }
    return $request;
});
$reviews->queue($owner,$server,$op->id,true);$app->call([new IByte\MinecraftManager\Operations\CheckReview($op->id,true),'handle']);$op->refresh();
$preserved=true;foreach($before as $path=>$hash){$preserved=$preserved&&$node->hash($path)===$hash;}
$temporary=[];foreach($op->plan['journal']??[] as $path=>$item){if(!empty($item['temporary'])&&$node->exists($path)){$temporary[]=$path;}}
$result=['scope'=>'The test removes only the second operation-owned temporary source immediately before the real native rename. Stock Wings returns success for a missing source; destination verification detects it after the first promotion and second original removal. Production rollback restores the private recovery copy; no HTTP/node/provider response is mocked.',
    'operation'=>$op->id,'status'=>$op->status,'error'=>$op->error_code,'fault_injected'=>$fault,'promotion_requests'=>$promotions,'original_mod_hashes_preserved'=>$preserved,'world_unchanged'=>hash_file('sha256',$world)===$worldHash,'reservation_released'=>$op->server_slot===null,'temporary_files_remaining'=>$temporary,'manager_directory_present'=>is_dir('/opt/imm/volumes/'.$server->uuid.'/.minecraft-manager'),'transfer'=>TransferProbe::result(),'recorded_at'=>gmdate('c')];
$result['passed']=$fault&&$promotions===2&&$op->status==='failed'&&$preserved&&$result['world_unchanged']&&$result['reservation_released']&&!$temporary&&!$result['manager_directory_present']&&$result['transfer']['recovery_uploads']>0;
file_put_contents('/opt/imm/live-rollback.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result,JSON_UNESCAPED_SLASHES),"\n";exit($result['passed']?0:1);
