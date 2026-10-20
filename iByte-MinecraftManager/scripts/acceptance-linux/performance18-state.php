<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){throw new RuntimeException('Isolated Linux DB required');}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/evidence.php';
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$results=[];
foreach(['neoforge1211fast','fabric1211','forge1201','paper1214','cobblemon'] as $name){
    $case=$fixture['cases'][$name];$root='/opt/imm/volumes/'.$case['uuid'];$temporary=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){if(str_starts_with($file->getFilename(),'.download-')){$temporary[]=$file->getFilename();}}
    $process=proc_open(['/usr/bin/docker','--host=unix:///run/imm-docker.sock','inspect','--format','{{json .}}',$case['uuid']],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($process)!==0){throw new RuntimeException('Owned game container missing');}$container=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $state=$container['State'];$row=['case'=>$name,'manager_directory_present'=>is_dir($root.'/.minecraft-manager'),'temporary_files_remaining'=>$temporary,'server_jar_present'=>is_file($root.'/server.jar'),'world_present'=>is_file($root.'/world/level.dat'),'container_running'=>$state['Running'],'exit_code'=>$state['ExitCode'],'oom_killed'=>$state['OOMKilled'],'image'=>$container['Config']['Image'],'image_id'=>$container['Image']];
    if($row['manager_directory_present']||$temporary||!$row['server_jar_present']||!$row['world_present']||$row['container_running']||$row['exit_code']!==0||$row['oom_killed']){throw new RuntimeException('Owned game layout/shutdown failed');}
    $results[]=$row;
}
$slots=IByte\MinecraftManager\Models\Operation::whereIn('server_id',array_column($fixture['cases'],'id'))->whereNotNull('server_slot')->count();
$jobs=Illuminate\Support\Facades\DB::table('jobs')->where('queue','minecraft-manager')->count();$credential=app(IByte\MinecraftManager\Support\Settings::class)->credential('curseforge');
if($slots||$jobs||$credential!==null){throw new RuntimeException('Reservation, queue or credential remains');}
$workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager';
$result=['passed'=>true,'scope'=>'Five actual tested game containers, verified final layouts, no active installer reservations, drained real queue and no CurseForge credential','php'=>PHP_VERSION,'debian'=>trim(file_get_contents('/etc/debian_version')),'wings_sha256'=>hash_file('sha256','/opt/imm/bin/wings'),'containers'=>$results,'active_reservations'=>$slots,'pending_jobs'=>$jobs,'credential_absent'=>$credential===null,'recorded_at'=>gmdate('c')];
file_put_contents(acceptanceEvidencePath('final-state.json'),json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo "PASS five clean game layouts/shutdowns; queue, reservations and credential cleared\n";
