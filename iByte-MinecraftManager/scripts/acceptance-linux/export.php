<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'){exit(1);}
$workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager';
$folder=getenv('IMM_ACCEPTANCE_EVIDENCE')?:'live-runtime-context';if(!in_array($folder,['live-acceptance','live-direct-downloads','live-direct-destinations','live-runtime-context','live-catalog17','live-performance18','live-verification19'],true)){throw new RuntimeException('Unknown evidence folder');}
$out=$workspace.'/docs/evidence/'.$folder;if(!is_dir($out)){mkdir($out,0700,true);}
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$summary=['scope'=>'Native Panel and official Wings RC.1 on isolated Debian 13, real Docker Java runtime, provider downloads and node readback. CLI invokes the production queued handler; automatic scheduler and UI are tested separately.','cases'=>[]];
$summary['startup_report_scope']='startup is the latest recorded boot on each server, including subsequent mod or pack installs. packages-startup and queued-pack-startup retain their phase-specific probes. case logs retain initial runtime command output where available.';
foreach($fixture['cases'] as $name=>$case){
    foreach(['installation','startup','packages','packages-startup','queued-pack','queued-pack-startup','before-pack-installation'] as $kind){$file='/opt/imm/'.$name.'-'.$kind.'.json';if(is_file($file)){$data=json_decode(file_get_contents($file),true);$summary['cases'][$name][$kind]=$data;copy($file,$out.'/'.$name.'-'.$kind.'.json');}}
    $process=proc_open(['/usr/bin/docker','--host=unix:///run/imm-docker.sock','logs',$case['uuid']],[0=>['file','/dev/null','r'],1=>['file','/opt/imm/logs/'.$name.'-console.log','w'],2=>['file','/opt/imm/logs/'.$name.'-console-errors.log','w']],$pipes);
    if(is_resource($process)){proc_close($process);}
    foreach(['game','console'] as $kind){$file='/opt/imm/logs/'.$name.'-'.$kind.'.log';if(is_file($file)){copy($file,$out.'/'.$name.'-'.$kind.'.log');}}
    foreach(['case','packages','queued-startup'] as $kind){$file='/opt/imm/logs/'.$name.'-'.$kind.'.log';if(is_file($file)){copy($file,$out.'/'.$name.'-'.$kind.'.log');}}
}
$summary['recorded_at']=gmdate('c');file_put_contents($out.'/matrix.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo 'Exported ',count($summary['cases'])," cases; partial/in-progress outcomes are retained.\n";
$scheduler='/opt/imm/live-scheduler.json';if(is_file($scheduler)){copy($scheduler,$out.'/live-scheduler.json');}
$rollback='/opt/imm/live-rollback.json';if(is_file($rollback)){copy($rollback,$out.'/live-rollback.json');}
$java='/opt/imm/live-plugin-java.json';if(is_file($java)){copy($java,$out.'/live-plugin-java.json');}
