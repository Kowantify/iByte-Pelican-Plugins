<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$node=App\Models\Node::factory()->create(['fqdn'=>'127.0.0.1','daemon_listen'=>18097,'daemon_connect'=>18097,'daemon_token_id'=>'verifycontract19']); // gitleaks:allow -- fixed public test identifier, not the generated secret token
$server=App\Models\Server::factory()->create(['node_id'=>$node->id]);
$adapter=new IByte\MinecraftManager\Operations\NodeFiles($server);
$stats=static fn(bool $reset=false)=>json_decode(file_get_contents('http://127.0.0.1:18097/__stats'.($reset?'?reset=1':'')),true);
$item=static fn(string $path,?string $bytes=null)=>['new'=>hash('sha256',$bytes??'verification:/'.$path),'size'=>strlen($bytes??'verification:/'.$path)];
$passed=0;$test=static function(bool $ok,string $message)use(&$passed){check($ok,$message);++$passed;echo "PASS $message\n";};
$baselineZip=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.18.zip';if(!is_file($baselineZip)){$baselineZip=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.18.zip';}
$zip=new ZipArchive;check($zip->open($baselineZip)===true);
$old=str_replace('namespace IByte\\MinecraftManager\\Operations;','namespace IByte\\MinecraftManager\\VerificationBaseline;',$zip->getFromName('ibyte-minecraft-manager/src/Operations/NodeFiles.php'));
$zip->close();file_put_contents($root.'/.build/verification-baseline19.php',$old);require $root.'/.build/verification-baseline19.php';
$baseline=new IByte\MinecraftManager\VerificationBaseline\NodeFiles($server);
$journal=[];for($i=0;$i<102;$i++){$path='normal-'.$i.'.jar';$journal[$path]=$item($path);}
$stats(true);$start=microtime(true);foreach($journal as $path=>$entry){check($baseline->hash($path)===$entry['new']);}$oldSeconds=microtime(true)-$start;$oldStats=$stats();
$stats(true);$progress=[];$start=microtime(true);$adapter->verifyMany($journal,static function($done,$total,$path)use(&$progress){$progress[]=['done'=>$done,'total'=>$total,'path'=>$path];});$newSeconds=microtime(true)-$start;$newStats=$stats();
$test($oldStats['maximum']===1&&$newStats['maximum']===4&&$newStats['requests']===102&&$newStats['active']===0,'native HTTP uses four concurrent reads, preserves every file check and settles all requests');
$test(count($progress)===102&&array_column($progress,'done')===range(1,102)&&$progress[101]['total']===102,'progress counts verified completions and reaches 100 percent only after every file');
$test($newSeconds<$oldSeconds*0.55,'102-file latency benchmark is substantially faster than immutable preview.18');
$stats(true);$large=[];for($i=0;$i<5;$i++){$large['big-'.$i.'.jar']=$item('big-'.$i.'.jar',str_repeat('a',1100000));}$adapter->verifyMany($large,static function(){});
$test($stats()['maximum']===4&&$stats()['active']===0,'verification streams larger than the in-memory threshold spill and still verify correctly');
$failureResults=[];
foreach(['checksum','missing','truncated','oversized','chunked','disconnect','callback'] as $scenario){
    $stats(true);$path=$scenario==='checksum'||$scenario==='callback'?'normal-failure.jar':$scenario.'.jar';$entry=$item($path);
    if($scenario==='checksum'){$entry['new']=str_repeat('0',64);}
    if($scenario==='truncated'){unset($entry['size']);}
    if($scenario==='chunked'){$entry['size']=16;}
    $batch=[$path=>$entry];for($i=0;$i<11;$i++){$p='normal-after-'.$i.'.jar';$batch[$p]=$item($p);}
    $failed=false;$completed=0;
    try{$adapter->verifyMany($batch,static function()use($scenario,&$completed){++$completed;if($scenario==='callback'){throw new RuntimeException('Injected progress persistence failure');}});}catch(Throwable){$failed=true;}
    $state=$stats();$failureResults[$scenario]=['failed'=>$failed,'requests'=>$state['requests'],'active'=>$state['active'],'progress'=>$completed];
    $test($failed&&$state['requests']===4&&$state['active']===0&&$completed<12,$scenario.' fails without launching later batches or leaving in-flight verification reads');
}
$stats(true);$adapter->verifyMany([],static function(){throw new RuntimeException('Empty verification must not report success progress');});
$test($stats()['requests']===0,'empty verification performs no HTTP requests');
$adapter->verifyMany(['empty.cfg'=>$item('empty.cfg','')],static function(){});
$test($stats()['active']===0,'empty pack configuration files verify successfully');
$stats(true);$failed=false;
try{$adapter->verifyMany(['normal-first.jar'=>$item('normal-first.jar'),'invalid.jar'=>['new'=>hash('sha256','x'),'size'=>-1]],static function(){});}catch(Throwable){$failed=true;}
$test($failed&&$stats()['requests']===1&&$stats()['active']===0,'request construction failure settles the already-started read before cleanup');
$stats(true);$done=0;$adapter->verifyMany(['missing-retired.jar'=>['new'=>null],'normal-kept.jar'=>$item('normal-kept.jar')],static function()use(&$done){++$done;});
$test($done===2&&$stats()['requests']===1,'retired paths are checked for absence while written paths retain full checksums');
$stats(true);$failed=false;try{$adapter->verifyMany(['existing-retired.jar'=>['new'=>null]],static function(){});}catch(Throwable){$failed=true;}
$test($failed&&$stats()['requests']===0,'a retired path that still exists blocks completion');
file_put_contents($root.'/docs/evidence/verification-preview.22.json',json_encode(['passed'=>$passed,'scope'=>'Production native NodeFiles HTTP against a real concurrent loopback HTTP server; 100ms response delay per file, not a mock transport or game boot','files'=>102,'baseline_seconds'=>round($oldSeconds,3),'concurrent_seconds'=>round($newSeconds,3),'speedup'=>round($oldSeconds/$newSeconds,2),'baseline_stats'=>$oldStats,'concurrent_stats'=>$newStats,'failures'=>$failureResults,'recorded_at'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "RESULT $passed asynchronous verification checks passed.\n";
