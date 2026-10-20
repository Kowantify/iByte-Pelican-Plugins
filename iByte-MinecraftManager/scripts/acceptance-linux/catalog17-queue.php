<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){throw new RuntimeException('Isolated Linux DB required');}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/transfer-probe.php';TransferProbe::start($app);require __DIR__.'/evidence.php';
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$server=App\Models\Server::findOrFail($fixture['cases']['neoforge263']['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);
$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);$node->assertStopped();
auth()->login($owner);Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));Filament\Facades\Filament::setTenant($server);
$world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$before=hash_file('sha256',$world);
$result=['scope'=>'Actual native Livewire queueProject/installQueued actions with live providers, database queue delivery through minecraft-manager:work and official Wings; no provider/node/process mock','case'=>'neoforge263','started_at'=>gmdate('c')];
try{
    $page=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class)->call('switchArea','packages');
    foreach(['Lithium','FerriteCore'] as $title){
        $page->set('query',$title)->call('search')->assertSet('error',null);
        $project=collect($page->get('results'))->first(fn($r)=>strcasecmp($r['title'],$title)===0)??throw new RuntimeException('Expected exact compatible project');
        $page->call('queueProject',$project['id'])->assertSet('error',null)->assertSet('projectOpen',false);
    }
    if(count($page->get('installQueue'))!==2){throw new RuntimeException('Expected two pending UI choices');}
    $page->call('installQueued')->assertSet('error',null)->assertSet('installQueue',[]);
    $operation=IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('server_slot',$server->id)->firstOrFail();
    $result['operation']=$operation->id;$result['queued_before_worker']=$operation->status==='queued';
    Illuminate\Support\Facades\Artisan::call('minecraft-manager:work',['--stop-when-empty'=>true]);$operation->refresh();
    $result+=['status'=>$operation->status,'error'=>$operation->error_code,'reservation_released'=>$operation->server_slot===null,'world_unchanged'=>hash_file('sha256',$world)===$before];
    $result['file_hashes_verified']=true;foreach($operation->plan['installed_files']??[] as $path=>$item){if($item['new']!==null&&$node->hash($path)!==$item['new']){$result['file_hashes_verified']=false;}}
    $result['passed']=$result['queued_before_worker']&&$result['status']==='succeeded'&&$result['reservation_released']&&$result['world_unchanged']&&$result['file_hashes_verified'];
}catch(Throwable $error){$result['passed']=false;$result['fatal']=$error instanceof IByte\MinecraftManager\Support\Failure?$error->reason:get_class($error).': '.$error->getMessage();}
$result['transfer']=TransferProbe::result();$result['finished_at']=gmdate('c');file_put_contents(acceptanceEvidencePath('live-ui-mod-queue.json'),json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result,JSON_UNESCAPED_SLASHES),"\n";exit($result['passed']?0:1);
