<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php'; require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$node=App\Models\Node::factory()->create(['fqdn'=>'127.0.0.1','daemon_listen'=>18097,'daemon_connect'=>18097,'daemon_token_id'=>'verifycontract19']); // gitleaks:allow -- fixed public test identifier, not the generated secret token
$server=App\Models\Server::factory()->create(['node_id'=>$node->id]);
$adapter=new IByte\MinecraftManager\Operations\NodeFiles($server);
$stats=static fn(bool $reset=false)=>json_decode(file_get_contents('http://127.0.0.1:18097/__stats'.($reset?'?reset=1':'')),true);
$item=static function(string $path,?string $bytes=null):array{$bytes??='verification:/'.$path; return ['size'=>strlen($bytes),'hashes'=>['sha512'=>hash('sha512',$bytes)]];};
$passed=0;$test=static function(bool $ok,string $message)use(&$passed):void{check($ok,$message);++$passed;echo "PASS $message\n";};
$reads=[];for($i=0;$i<100;$i++){$path='normal-initial-'.$i.'.jar';$reads[$path]=$item($path);}
$stats(true);$start=microtime(true);foreach($reads as $path=>$entry){$adapter->verifiedRead($path,$entry['size'],$entry['hashes']);}$serial=microtime(true)-$start;$serialStats=$stats();
$stats(true);$start=microtime(true);$done=[];$heartbeats=0;
$adapter->verifiedReadMany($reads,static function($path,$result)use(&$done){check($result['sha256']===hash('sha256','verification:/'.$path));$done[$path]=$result;},static function()use(&$heartbeats){++$heartbeats;});
$parallel=microtime(true)-$start;$parallelStats=$stats();
$test(count($done)===100&&$serialStats['maximum']===1&&$parallelStats['maximum']===4&&$parallelStats['requests']===100&&$parallelStats['active']===0,'100 native HTTP publisher checks use four concurrent reads and verify every SHA-512 and SHA-256');
$test($parallel<$serial*0.55&&$heartbeats>100,'initial verification is substantially faster and emits in-flight cancellation/progress heartbeats');
$stats(true);$reads=[];for($i=0;$i<1000;$i++){$path='normal-scale-'.$i.'.jar';$reads[$path]=$item($path);}
$start=microtime(true);$count=0;$adapter->verifiedReadMany($reads,static function()use(&$count){++$count;},static function(){});$largeSeconds=microtime(true)-$start;$scaleStats=$stats();
$test($count===1000&&$scaleStats['maximum']===4&&$scaleStats['requests']===1000&&$scaleStats['active']===0,'1000-file pack stays bounded at four reads and completes every file');
$copies=[];$reads=[];
for($i=0;$i<5;$i++){$path='big-initial-'.$i.'.jar';$copies[]=$copy=$root.'/.build/inspect-initial-'.$i.'.jar';$reads[$path]=[...$item($path,str_repeat('a',1100000)),'destination'=>$copy];}
$count=0;$adapter->verifiedReadMany($reads,static function($path,$result)use(&$count,$reads){check(hash_file('sha256',$reads[$path]['destination'])===$result['sha256']);++$count;},static function(){});
$test($count===5&&!array_filter($copies,'file_exists'),'inspection copies are independently readable and removed after callbacks');
$stats(true);$reads=[];for($i=0;$i<5;$i++){$path='big-buffer-'.$i.'.jar';$reads[$path]=$item($path,str_repeat('a',1100000));}
$adapter->verifiedReadMany($reads,static function(){},static function(){});
$test($stats()['maximum']===4&&$stats()['active']===0,'large checksum-only streams spill beyond the one MiB memory threshold');
$failures=[];
foreach(['checksum','missing','truncated','oversized','chunked','disconnect','callback','heartbeat'] as $scenario){
    $stats(true);$path=in_array($scenario,['checksum','callback','heartbeat'],true)?'normal-initial-failure.jar':$scenario.'.jar';$entry=$item($path);
    if($scenario==='checksum'){$entry['hashes']['sha512']=str_repeat('0',128);}
    if($scenario==='truncated'){unset($entry['size']);}
    if($scenario==='chunked'){$entry['size']=16;}
    $entry['destination']=$copy=$root.'/.build/inspect-failure.jar';$reads=[$path=>$entry];for($i=0;$i<11;$i++){$p='normal-initial-after-'.$i.'.jar';$reads[$p]=$item($p);}
    $failed=false;$count=0;$beats=0;
    try{$adapter->verifiedReadMany($reads,static function()use(&$count,$scenario){++$count;if($scenario==='callback'){throw new RuntimeException('Injected cancellation');}},static function()use(&$beats,$scenario){if($scenario==='heartbeat'&&++$beats>4){throw new RuntimeException('Injected heartbeat cancellation');}});}catch(Throwable){$failed=true;}
    $s=$stats();$failures[$scenario]=['requests'=>$s['requests'],'active'=>$s['active'],'completed'=>$count];
    $test($failed&&$s['requests']<=4&&$s['active']===0&&!file_exists($copy)&&$count<12,$scenario.' stops subsequent batches, settles current reads and removes inspection copies');
}
foreach(['invalid_hash','weak_integrity_policy','artifact_size_limit'] as $scenario){
    $entry=$item('invalid.jar');
    if($scenario==='invalid_hash'){$entry['hashes']=['sha512'=>'bad'];}
    if($scenario==='weak_integrity_policy'){$entry['hashes']=['md5'=>str_repeat('0',32)];}
    if($scenario==='artifact_size_limit'){$entry['size']=-1;}
    $stats(true);rejects(fn()=>$adapter->verifiedReadMany(['invalid.jar'=>$entry],static function(){},static function(){}),$scenario);
    $test($stats()['requests']===0,$scenario.' is rejected before a request');
}
$stats(true);$adapter->verifiedReadMany([],static function(){throw new RuntimeException('Unexpected callback');},static function(){throw new RuntimeException('Unexpected heartbeat');});
$test($stats()['requests']===0,'empty initial verification performs no work');
$copy=$root.'/.build/inspect-existing.jar';file_put_contents($copy,'operator bytes');$failed=false;
try{$adapter->verifiedReadMany(['normal-copy.jar'=>[...$item('normal-copy.jar'),'destination'=>$copy]],static function(){},static function(){});}catch(Throwable){$failed=true;}
$test($failed&&file_get_contents($copy)==='operator bytes','inspection never overwrites an existing local path');unlink($copy);
$clock=new IByte\MinecraftManager\Operations\ProgressClock;
$a=$clock->snapshot(['phase'=>'download']);usleep(10000);$b=$clock->snapshot(['phase'=>'verify']);usleep(10000);$c=$clock->snapshot(['phase'=>'download']);usleep(10000);$d=$clock->snapshot(['phase'=>'cleanup']);
$test($d['phase_seconds']['download']>=0.018&&$d['phase_seconds']['verify']>=0.009&&$d['elapsed_seconds']>=0.028&&$d['elapsed_seconds']>=$b['elapsed_seconds'],'monotonic phase timings accumulate recurring phases and preserve total processing time');
file_put_contents($root.'/docs/evidence/initial-verification-preview.22.json',json_encode(['passed'=>$passed,'scope'=>'Production NodeFiles against real concurrent authenticated loopback HTTP, 100 ms response delay; not Wings or a game boot','serial_seconds'=>round($serial,3),'parallel_seconds'=>round($parallel,3),'speedup'=>round($serial/$parallel,2),'serial'=>$serialStats,'parallel'=>$parallelStats,'scale_files'=>1000,'scale_seconds'=>round($largeSeconds,3),'scale'=>$scaleStats,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "RESULT $passed initial verification checks passed.\n";
