<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$http=app(IByte\MinecraftManager\Support\SafeHttp::class);$samples=[];
for($i=0;$i<3;$i++){
    $start=microtime(true);$data=$http->get('modrinth','/v2/project/uXXizFIs');check($data['id']==='uXXizFIs');
    $clients=(new ReflectionProperty($http,'clients'))->getValue($http);$info=curl_getinfo($clients['modrinth']);
    $samples[]=['elapsed_ms'=>round((microtime(true)-$start)*1000),'new_connections'=>curl_getinfo($clients['modrinth'],CURLINFO_NUM_CONNECTS)];
}
check($samples[0]['new_connections']===1 && $samples[1]['new_connections']===0 && $samples[2]['new_connections']===0,'Provider connection was not reused');
file_put_contents($root.'/docs/evidence/provider-connection-reuse.json',json_encode(['provider'=>'Live public Modrinth API','requests'=>$samples,'passed'=>true,'scope'=>'Three actual metadata requests, no response cache; first TLS connection reused by following two requests'],JSON_PRETTY_PRINT)."\n");
echo "PASS live provider requests reuse the TLS connection without response caching\n";
