<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$passed=0;
foreach(['NEOFORGE'=>'NeoForge','PAPER'=>'Paper','FORGE'=>'Forge'] as $type=>$name){
    $http=new FixtureTransport(['mcjars/api/v3/builds/build27'=>['build'=>['uuid'=>'build27','type'=>$type,'version_id'=>'1.21.1','name'=>'21.1.262','installation'=>[]],'version'=>['java'=>21,'type'=>'RELEASE']]]);
    $release=(new IByte\MinecraftManager\Catalog\McJars($http))->exact($type,'1.21.1','build27');
    $operation=new IByte\MinecraftManager\Models\Operation(['kind'=>'software','plan'=>['release'=>$release]]);
    check($operation->displayTitle()===$name.' 1.21.1 · 21.1.262','Software name, Minecraft version and build must be present');
    check(count($http->calls)===1,'Label must not add provider requests');
    ++$passed;echo "PASS $name includes software name, Minecraft version and build without extra requests\n";
}
file_put_contents($root.'/docs/evidence/software-labels-preview.27.json',json_encode(['passed'=>$passed,'scope'=>'Focused exact-metadata and operation-label fixtures; no install-engine or browser rerun'],JSON_PRETTY_PRINT)."\n");
echo "RESULT 3 software label checks passed.\n";
