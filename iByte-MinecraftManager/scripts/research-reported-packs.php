<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app=require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$http=app(IByte\MinecraftManager\Catalog\JsonTransport::class);
$settings=app(IByte\MinecraftManager\Support\Settings::class);
$policy=$settings->values(); $credential=$settings->credential('curseforge');
$results=[];
try {
    $settings->save(['providers'=>['mcjars','modrinth','curseforge']]);
    $hits=$http->get('modrinth','/v2/search',['query'=>'Cobblemon Official Modpack','facets'=>'[["project_type:modpack"]]','limit'=>20])['hits'];
    $hit=collect($hits)->first(fn($entry)=>str_contains($entry['title'],'Official'))??throw new RuntimeException('Official pack not found');
    $versions=$http->get('modrinth','/v2/project/'.$hit['project_id'].'/version',['include_changelog'=>'false']);
    $version=collect($versions)->first(fn($entry)=>str_contains($entry['name'],'1.8.2')&&in_array('fabric',$entry['loaders'],true))??throw new RuntimeException('Reported exact pack release not found');
    $results['cobblemon']=['provider'=>'modrinth','project'=>$hit['project_id'],'release'=>$version['id'],'title'=>$version['name']];
    $key=getenv('IMM_TEST_CF_KEY');
    if ($key) {
        $settings->replaceCredential('curseforge',$key); unset($key); putenv('IMM_TEST_CF_KEY');
        $hits=$http->get('curseforge','/v1/mods/search',['gameId'=>432,'classId'=>4471,'searchFilter'=>'iByte Nexus','pageSize'=>20])['data'];
        $hit=collect($hits)->first(fn($entry)=>str_contains($entry['name'],'Nexus'))??throw new RuntimeException('Reported CurseForge pack not found');
        $versions=$http->get('curseforge','/v1/mods/'.$hit['id'].'/files',['pageSize'=>50])['data'];
        $version=collect($versions)->first(fn($entry)=>str_contains($entry['displayName'],'V1.6'))??throw new RuntimeException('Reported exact CurseForge release not found');
        $results['nexus']=['provider'=>'curseforge','project'=>(string)$hit['id'],'release'=>(string)$version['id'],'title'=>$version['displayName']];
    }
    foreach($results as $name=>&$case) {
        $directory=$root.'/.build/live-cases/'.$name; if(!is_dir($directory)){mkdir($directory,0700,true);}
        $artifact=app(IByte\MinecraftManager\Catalog\Catalog::class)->provider($case['provider'])->artifact($case['project'],$case['release']);
        $archive=$directory.'/pack.zip';
        if(!is_file($archive)){app(IByte\MinecraftManager\Support\ArtifactDownloader::class)->download($artifact['url'],$archive,$artifact['size'],$artifact['hashes']);}
        $zip=new ZipArchive; $zip->open($archive,ZipArchive::RDONLY);
        $manifest=json_decode($zip->getFromName($name==='cobblemon'?'modrinth.index.json':'manifest.json'),true,64,JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $paths=[]; foreach($manifest['files'] as $file){$path=$file['path']??'';if($path){$first=explode('/',$path)[0];$paths[$first]=($paths[$first]??0)+1;}}
        $case['paths']=$paths; $case['dependencies']=$manifest['dependencies']??$manifest['minecraft'];
        echo $name,': ',$case['project'],' / ',$case['release'],' / ',count($manifest['files'])," manifest entries\n";
        if($name==='cobblemon'){
            foreach($manifest['files'] as $file){if(!in_array(explode('/',$file['path'])[0],['mods','plugins','config','defaultconfigs','kubejs','scripts','patchouli_books','global_packs','datapacks'],true)){
                echo 'Previously refused path: ',$file['path'],' / server=',($file['env']['server']??'required'),"\n";
            }}
        }
        $zip->close();
    }
    unset($case);
    file_put_contents($root.'/docs/evidence/reported-pack-cases.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
} finally {$settings->replaceCredential('curseforge',$credential);$settings->save($policy);}
