<?php
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=app(IByte\MinecraftManager\Support\Settings::class);$http=new IByte\MinecraftManager\Support\SafeHttp($settings);$catalog=new IByte\MinecraftManager\Catalog\Catalog($http,$settings);$provider=new IByte\MinecraftManager\Catalog\Modrinth($http);$rows=[];
foreach([['mod','forge','1.21.1',''],['mod','neoforge','1.21.1',''],['plugin','paper','1.21.4','WorldEdit'],['plugin','spigot','1.21.4','WorldEdit'],['plugin','bukkit','1.21.4','WorldEdit']] as [$type,$loader,$version,$query]){
    $context=$catalog->context(compact('type','loader','version','query'));$result=$provider->search($context);$row=['type'=>$type,'loader'=>$loader,'version'=>$version,'query'=>$query,'total'=>$result['total'],'sample'=>array_slice($result['items'],0,3)];
    if($query!==''){$project=collect($result['items'])->first(fn($project)=>$project['title']===$query)??throw new RuntimeException('Missing expected live project');$row['releases']=array_slice($provider->releases($project['id'],$context),0,2);if(!$row['releases']){throw new RuntimeException('No compatible live releases');}}
    $rows[]=$row;echo json_encode(['loader'=>$loader,'version'=>$version,'total'=>$row['total'],'release_loaders'=>$row['releases'][0]['loaders']??null]),"\n";
}
$path=$root.'/docs/evidence/live-runtime-context';if(!is_dir($path)){mkdir($path,0700,true);}file_put_contents($path.'/catalog-filters.json',json_encode(['scope'=>'Actual Modrinth search and exact version/platform release filters; no cache or provider fixtures','cases'=>$rows,'recorded_at'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
