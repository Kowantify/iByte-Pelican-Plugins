<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use IByte\MinecraftManager\Support\Settings;
use IByte\MinecraftManager\Operations\ReviewService;
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$settings=app(Settings::class);$previous=$settings->credential('curseforge');$key=getenv('IMM_TEST_CF_KEY');if($key){$settings->replaceCredential('curseforge',$key);unset($key);putenv('IMM_TEST_CF_KEY');}
$all=[];
try{
    foreach(['cobblemon','nexus','odyssey'] as $name){
        $case=$fixture['cases'][$name];$server=App\Models\Server::findOrFail($case['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);
        $world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$before=hash_file('sha256',$world);
        $beforeEnvironment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server);$beforeStartup=$server->startup;
        $reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'pack','provider'=>$case['provider'],'project'=>$case['project'],'release'=>$case['release'],'type'=>'modpack','experimental'=>true]);
        $reviews->queue($owner,$server,$op->id,true);
        $event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='minecraft-manager-installs')??throw new RuntimeException('Missing scheduler event');
        for($i=0;$i<30&&$event->mutex->exists($event);$i++){sleep(1);}
        if($event->mutex->exists($event)||!$event->filtersPass($app)){throw new RuntimeException('Background worker unavailable');}
        $event->run($app);$started=gmdate('c');$last='';$fractional=false;echo 'Queued final ZIP: ',$name,' / ',$op->id,"\n";flush();
        for($i=0;$i<1800;$i++){
            sleep(1);$op->refresh();$progress=$op->plan['progress']??[];$state=$op->status.':'.($progress['phase']??'').':'.($progress['files']??0);
            if(($progress['phase']??'')==='download'&&($progress['fraction']??0)>0&&($progress['fraction']??1)<1){$fractional=true;}
            if($state!==$last){echo $name,' ',$state,' ',($progress['file']??''),"\n";$last=$state;flush();}
            if(!in_array($op->status,['queued','preflight','downloading','verifying','applying','verifying-result'],true)){break;}
        }
        $result=['case'=>$name,'scope'=>'Exact preview.14 ZIP imported by published Panel RC.1; actual native background queue, direct Wings pulls, live providers, official Wings RC.1; reinstall onto an existing world','operation'=>$op->id,'started_at'=>$started,'status'=>$op->status,'error'=>$op->error_code,'failure'=>$op->plan['failure']??null,'files'=>count($op->plan['installed_files']??[]),'source_resolutions'=>$op->plan['source_resolutions']??[],'fractional_download_progress_observed'=>$fractional,'world_unchanged'=>hash_file('sha256',$world)===$before,'reservation_released'=>$op->server_slot===null,'finished_at'=>gmdate('c')];
        $fresh=$server->fresh();$runtime=IByte\MinecraftManager\Models\Setting::find('runtime-'.$server->id)?->value??[];$environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($fresh);
        $result['runtime_variables_match']=$runtime&&!array_diff_assoc(IByte\MinecraftManager\Support\RuntimeVariables::updates($fresh,$runtime),$environment);
        $result['custom_variables_preserved']=!array_diff_assoc(array_intersect_key($beforeEnvironment,array_flip(['JVM_ARGS','MAXIMUM_MEMORY'])),$environment);$result['startup_preserved']=$fresh->startup===$beforeStartup;
        $all[$name]=$result;file_put_contents('/opt/imm/'.$name.'-queued-pack.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        if($op->status!=='succeeded'||!$result['world_unchanged']||!$result['reservation_released']||!$result['runtime_variables_match']||!$result['custom_variables_preserved']||!$result['startup_preserved']){throw new RuntimeException('Queued pack failed: '.$name.' / '.($op->error_code??$op->status));}
        // Boot through the same native API in a separate PHP process after install.
        $process=proc_open([PHP_BINARY,__DIR__.'/boot.php',$name],[0=>['file','/dev/null','r'],1=>['file','/opt/imm/logs/'.$name.'-queued-startup.log','w'],2=>['file','/opt/imm/logs/'.$name.'-queued-startup-errors.log','w']],$pipes);
        if(!is_resource($process)||proc_close($process)!==0){throw new RuntimeException('Queued pack startup failed: '.$name);}
        copy('/opt/imm/'.$name.'-startup.json','/opt/imm/'.$name.'-queued-pack-startup.json');
        echo 'PASS final queued pack and boot: ',$name,"\n";flush();
    }
}finally{$settings->replaceCredential('curseforge',$previous);}
