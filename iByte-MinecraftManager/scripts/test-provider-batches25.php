<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled'=>true,'providers'=>['curseforge','modrinth']]);
$http=new class implements IByte\MinecraftManager\Catalog\JsonTransport {
    public array $calls=[];public string $mode='';
    public function file(int $id):array{
        return ['id'=>$id,'modId'=>$id-4000,'fileName'=>'mod-'.$id.'.jar','fileLength'=>7,'displayName'=>'Mod '.$id,'releaseType'=>1,
            'hashes'=>[['algo'=>1,'value'=>sha1((string)$id)]],'gameVersions'=>['1.20.1','Forge'],'dependencies'=>[],
            'downloadUrl'=>'https://edge.forgecdn.net/mod-'.$id.'.jar'];
    }
    public function post(string $provider,string $path,array $body):array{
        $this->calls[]=[$provider,'POST',$path,$body];
        if($provider==='curseforge'&&$this->mode==='outage'){throw new IByte\MinecraftManager\Support\Failure('rate_limited');}
        if($provider==='curseforge'&&$path==='/v1/mods'){
            check(count($body['modIds'])<=50);
            return ['data'=>array_reverse(array_map(fn($id)=>['id'=>$id,'classId'=>$id===1000?12:6,'name'=>'Project '.$id],$body['modIds']))];
        }
        if($provider==='curseforge'&&$path==='/v1/mods/files'){
            check(count($body['fileIds'])<=50);$files=array_map($this->file(...),$body['fileIds']);
            if($this->mode==='missing'){array_pop($files);}
            if($this->mode==='identity'){$files[0]['modId']=999;}
            return ['data'=>array_reverse($files)];
        }
        if($provider==='modrinth'&&$path==='/v2/version_files'){
            if($this->mode==='unknown'){throw new IByte\MinecraftManager\Support\Failure('provider_unavailable');}
            $out=[];foreach([5001,5002,5003] as $id){$hash=sha1((string)$id);if(in_array($hash,$body['hashes'],true)){
                $out[$hash]=['project_id'=>'project'.$id,'files'=>[['hashes'=>['sha1'=>$hash],'size'=>$id===5002?8:7]]];
            }}return $out;
        }
        throw new RuntimeException('Unexpected batch endpoint');
    }
    public function get(string $provider,string $path,array $query=[]):array{
        $this->calls[]=[$provider,'GET',$path,$query];check($provider==='modrinth'&&$path==='/v2/projects','No per-mod requests allowed');
        return array_map(fn($id)=>['id'=>$id,'server_side'=>$id==='project5001'?'unsupported':'required'],json_decode($query['ids'],true));
    }
};
$adapter=new IByte\MinecraftManager\Catalog\CurseForge($http);
$manifest=[];for($i=0;$i<51;$i++){$manifest[]=['projectID'=>1000+$i,'fileID'=>5000+$i,'required'=>true];}
$passed=0;$test=static function(bool $ok,string $name)use(&$passed):void{check($ok,$name);++$passed;echo "PASS $name\n";};
$guards=0;$result=iterator_to_array($adapter->manifestReleases($manifest,function()use(&$guards){++$guards;}));
$calls=$http->calls;$cf=array_filter($calls,fn($c)=>$c[0]==='curseforge');
$test(count($result)===51&&count($cf)===4&&$guards===4,'51 manifest entries resolve in two bounded project/file batches without individual lookups');
$test($result[0]['skip']==='client_asset'&&$result[1]['skip']==='client_mod','resource packs and proven client-only mods remain excluded');
$test(isset($result[2]['artifact'],$result[3]['artifact'])&&$result[50]['id']==='5050','hash-size mismatch is not treated as client-only and unordered batch responses preserve exact identities/order');
$test($result[3]['project_title']==='Project 1003'&&(new IByte\MinecraftManager\Models\Operation(['kind'=>'package','plan'=>['release'=>$result[3]]]))->displayTitle()==='Project 1003 · Mod 5003','CurseForge project name is retained alongside the release');
$test(count(array_filter($calls,fn($c)=>$c[0]==='modrinth'&&$c[2]==='/v2/version_files'))===2&&count(array_filter($calls,fn($c)=>$c[2]==='/v2/projects'))===1,'Modrinth environment checks use batched exact hashes and projects');
$http->mode='missing';rejects(fn()=>iterator_to_array($adapter->manifestReleases(array_slice($manifest,1,2),static function(){})),'exact_release_unavailable');$test(true,'missing bulk files fail without selecting another version');
$http->mode='identity';rejects(fn()=>iterator_to_array($adapter->manifestReleases(array_slice($manifest,1,2),static function(){})),'exact_release_unavailable');$test(true,'cross-project bulk files are rejected');
$http->mode='outage';rejects(fn()=>iterator_to_array($adapter->manifestReleases($manifest,static function(){})),'rate_limited');$test(true,'provider failure is not converted into a partial successful pack');
$http->mode='unknown';$unknown=iterator_to_array($adapter->manifestReleases(array_slice($manifest,1,3),static function(){}));$test(count($unknown)===3&&isset($unknown[0]['artifact']),'optional environment lookup outages never silently omit required mods');
$http->mode='';$http->calls=[];$optional=iterator_to_array($adapter->manifestReleases([['projectID'=>1001,'fileID'=>5001,'required'=>false]],static function(){}));$test($optional===[]&&$http->calls===[],'optional manifest entries do not trigger provider requests');
$http->calls=[];$cancelled=false;try{iterator_to_array($adapter->manifestReleases($manifest,static function(){throw new RuntimeException('cancel');}));}catch(RuntimeException){$cancelled=true;}
$test($cancelled&&$http->calls===[],'cancellation is checked before requesting the next provider batch');
file_put_contents($root.'/docs/evidence/provider-batches-preview.25.json',json_encode(['passed'=>$passed,'manifest_entries'=>51,'curseforge_requests'=>count($cf),'modrinth_requests'=>count($calls)-count($cf),'scope'=>'Small deterministic adapter contract checks using documented bulk endpoints; no live provider requests'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "RESULT $passed provider batch checks passed.\n";
