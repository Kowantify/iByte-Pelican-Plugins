<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){throw new RuntimeException('Isolated Linux DB required');}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$name=$argv[1]??'cobblemon';$case=$fixture['cases'][$name];
$server=App\Models\Server::findOrFail($case['id']);$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);
$repo=app(App\Repositories\Daemon\DaemonServerRepository::class)->setServer($server);
$result=['case'=>$name,'started_at'=>gmdate('c'),'scope'=>'Actual Wings power, Docker game runtime, Done log and Minecraft TCP status protocol'];
function varInt(int $value):string{$value&=0xffffffff;$out='';do{$part=$value&127;$value>>=7;$out.=chr($part|($value?128:0));}while($value);return $out;}
function readVar($stream):int{$out=0;for($i=0;$i<5;$i++){$c=fread($stream,1);if($c===''){throw new RuntimeException('Incomplete status packet');}$b=ord($c);$out|=($b&127)<<($i*7);if(!($b&128)){return $out;}}throw new RuntimeException('Invalid status packet');}
function status(int $port,int $protocol):array{
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2);if(!$socket){throw new RuntimeException('Game port not ready');}
    stream_set_timeout($socket,3);
    try{$host='127.0.0.1';$packet=varInt(0).varInt($protocol).varInt(strlen($host)).$host.pack('n',$port).varInt(1);fwrite($socket,varInt(strlen($packet)).$packet."\x01\x00");
        readVar($socket);if(readVar($socket)!==0){throw new RuntimeException('Unexpected status response');}$length=readVar($socket);if($length<1||$length>1048576){throw new RuntimeException('Invalid status size');}
        $json='';while(strlen($json)<$length){$chunk=fread($socket,$length-strlen($json));if($chunk===''){throw new RuntimeException('Truncated status');}$json.=$chunk;}
        return json_decode($json,true,32,JSON_THROW_ON_ERROR);
    }finally{fclose($socket);}
}
try{
    $node->assertStopped();
    // Test servers only: product code never accepts EULA or automatically starts games.
    foreach(['eula.txt'=>"eula=true\n",'server.properties'=>"server-ip=127.0.0.1\nserver-port={$case['port']}\nonline-mode=false\nview-distance=3\nsimulation-distance=3\nmax-players=2\nlevel-seed=1\nenable-query=false\nspawn-protection=0\n"] as $path=>$contents){
        $file=tempnam('/opt/imm/tmp','boot');file_put_contents($file,$contents);$node->upload($path,$file);unlink($file);
    }
    $expected=IByte\MinecraftManager\Models\Setting::find('runtime-'.$server->id)?->value['version']??throw new RuntimeException('Missing known runtime');
    $protocol=match($expected){'1.20.1'=>763,'1.21.1'=>767,'1.21.4'=>769,'26.3'=>777,default=>throw new RuntimeException('Add an observed protocol mapping for this test version')};
    $launchTime=time();
    $repo->getHttpClient()->post('/api/servers/'.$server->uuid.'/power',['action'=>'start'])->throw();
    $deadline=time()+900;$ready=false;
    while(time()<$deadline){
        sleep(5);$details=$repo->getDetails(5);$state=$details['state']??'unknown';
        $path='/opt/imm/volumes/'.$server->uuid.'/logs/latest.log';clearstatcache(true,$path);$logs=is_file($path)&&filemtime($path)>=$launchTime?file_get_contents($path):'';
        if(str_contains($logs,'Done (')){try{$response=status($case['port'],$protocol);if(($response['version']['protocol']??null)!==$protocol||!str_contains($response['version']['name']??'',$expected)){$result['rejected_status_response']=$response;throw new RuntimeException('Incomplete Minecraft status response');}$result['game_status']=['version'=>$response['version'],'players'=>$response['players']??null];$result['expected_protocol_verified']=true;$result['fresh_done_log']=true;$ready=true;break;}catch(Throwable){}}
        if($state==='offline'){throw new RuntimeException('Game stopped before readiness');}
        echo 'Waiting for ',$name,': ',$state,"\n";flush();
    }
    if(!$ready){throw new RuntimeException('Game readiness timed out');}
    $process=proc_open(['/usr/bin/docker','--host=unix:///run/imm-docker.sock','inspect','--format','{{json .Config.Env}}',$server->uuid],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot inspect the owned game container');}$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($process)!==0){throw new RuntimeException('Container inspection failed');}
    $container=[];foreach(json_decode($raw,true,512,JSON_THROW_ON_ERROR) as $entry){[$key,$value]=array_pad(explode('=',$entry,2),2,'');$container[$key]=$value;}
    $wanted=array_intersect_key(IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh()),array_flip(['SERVER_TYPE','VERSION','BUILDS','SERVER_JARFILE','JVM_ARGS','MAXIMUM_MEMORY']));
    $result['runtime_variables_in_container_verified']=!array_diff_assoc($wanted,$container);if(!$result['runtime_variables_in_container_verified']){throw new RuntimeException('Wings did not apply the runtime environment');}$result['container_runtime_environment']=$wanted;
    $result['ready']=true;$result['ready_at']=gmdate('c');$result['world_created']=is_file('/opt/imm/volumes/'.$server->uuid.'/world/level.dat');
    echo 'READY ',$name,': ',json_encode($result['game_status']),"\n";
}catch(Throwable $error){$result['error']=get_class($error).': '.$error->getMessage();echo 'FAIL ',$name,': ',$result['error'],"\n";}
finally{
    try{$repo->getHttpClient()->post('/api/servers/'.$server->uuid.'/power',['action'=>'stop'])->throw();for($i=0;$i<60;$i++){if(($repo->getDetails(5)['state']??'')==='offline'){break;}sleep(2);}$result['stopped']=($repo->getDetails(5)['state']??'')==='offline';}catch(Throwable $error){$result['stop_error']=get_class($error);}
    $path='/opt/imm/volumes/'.$server->uuid.'/logs/latest.log';if(is_file($path)){copy($path,'/opt/imm/logs/'.$name.'-game.log');}
    $result['finished_at']=gmdate('c');file_put_contents('/opt/imm/'.$name.'-startup.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
exit(!empty($result['ready'])&&!empty($result['stopped'])?0:1);
