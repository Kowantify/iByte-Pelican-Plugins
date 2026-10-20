<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$passed=0;$test=static function(bool $ok,string $message)use(&$passed):void{check($ok,$message);++$passed;echo "PASS $message\n";};
$process=proc_open([$root.'/.tools/node/node-v22.23.3-win-x64/node.exe',$root.'/scripts/metadata-http-fixture.mjs'],[['pipe','r'],['pipe','w'],['file',$root.'/.build/metadata-http-25-error.log','a']],$pipes,$root);
try {
    $port=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR)['port'];
    $node=App\Models\Node::factory()->create(['fqdn'=>'127.0.0.1','daemon_listen'=>$port,'daemon_connect'=>$port,'daemon_token_id'=>'metadata25']); // gitleaks:allow -- public fixture identifier
    $server=App\Models\Server::factory()->create(['node_id'=>$node->id]);
    $adapter=new IByte\MinecraftManager\Operations\NodeFiles($server);
    $stats=static fn(string $query='')=>json_decode(file_get_contents('http://127.0.0.1:'.$port.'/__stats'.$query),true,512,JSON_THROW_ON_ERROR);
    $files=[];for($i=0;$i<12;$i++){$files['config/d'.$i.'/value.txt']=['size'=>1];}
    $stats('?reset=1');$start=microtime(true);
    foreach($files as $path=>$_){app(App\Repositories\Daemon\DaemonFileRepository::class)->setServer($server)->getDirectory('/'.dirname($path));}
    $serial=microtime(true)-$start;$serialStats=$stats();
    $stats('?reset=1');$start=microtime(true);$counts=[];
    $adapter->checkSizes($files,function($done,$total)use(&$counts){$counts[]=$done;check($total===12);});
    $parallel=microtime(true)-$start;$parallelStats=$stats();
    $test($parallelStats['maximum']===4&&$parallelStats['active']===0&&$parallelStats['requests']===14,'size checks use four native concurrent metadata requests with shared parent listings');
    $test($parallel<$serial*0.8,'small delayed-HTTP size check is faster despite two extra ancestor listings');
    $test(max($counts)===12&&in_array(4,$counts,true)&&in_array(8,$counts,true),'size-check progress reports completed files during directory batches');
    $stats('?reset=1');$tree=$adapter->tree('archive',static function(){});$treeStats=$stats();
    $test(count($tree)===12&&$treeStats['requests']===13&&$treeStats['maximum']===4,'archive traversal lists sibling directories concurrently');
    rejects(fn()=>$adapter->entries(['unsafe/link/secret.txt']),'node_path_collision');
    $test(!in_array('unsafe/link',$stats()['paths'],true),'symlink ancestors are rejected before requesting descendants');
    $bad=$files;$bad['config/d0/value.txt']['size']=2;
    rejects(fn()=>$adapter->checkSizes($bad),'size_mismatch');$test(true,'size mismatch remains a hard failure');
    rejects(fn()=>$adapter->checkSizes(['config/d0/missing.txt'=>['size'=>1]]),'size_mismatch');$test(true,'missing final files remain a hard failure');
    $stats('?reset=1&mode=failure');$failed=false;
    try{$adapter->checkSizes($files);}catch(Throwable){$failed=true;}
    $errorStats=$stats();$test($failed&&$errorStats['active']===0&&$errorStats['requests']===6,'failed metadata batch settles and prevents later requests');
    $stats('?reset=1');$guards=0;$cancelled=false;
    try{$adapter->tree('archive',function()use(&$guards){if(++$guards===4){throw new RuntimeException('cancel');}});}catch(RuntimeException){$cancelled=true;}
    $test($cancelled&&$stats()['active']===0&&$stats()['requests']===5,'scan cancellation settles the current batch and stops subsequent work');
    $stats('?reset=1');$adapter->mkdirMany(['new/a/leaf','new/b/leaf','new/c/leaf','new/d/leaf']);$made=$adapter->entries(['new/a/leaf','new/d/leaf']);
    $test(count($made)===2&&$stats()['maximum']===4,'directory creation runs independent siblings together after their parents');
    file_put_contents($root.'/docs/evidence/metadata-preview.25.json',json_encode(['passed'=>$passed,'serial_seconds'=>round($serial,3),'parallel_seconds'=>round($parallel,3),'speedup'=>round($serial/$parallel,2),'serial'=>$serialStats,'parallel'=>$parallelStats,'tree'=>$treeStats,'failure'=>$errorStats,'scope'=>'Small production NodeFiles check against authenticated concurrent loopback HTTP with 60ms delay; not real Wings or a modpack benchmark'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
} finally {
    foreach($pipes as $pipe){fclose($pipe);}proc_terminate($process);proc_close($process);
}
echo "RESULT $passed metadata checks passed.\n";
