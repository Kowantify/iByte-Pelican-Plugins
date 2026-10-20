<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/.research/panel/vendor/autoload.php';require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(in_array('--import',$argv,true)){
    Illuminate\Support\Facades\Process::fake(['*'=>Illuminate\Support\Facades\Process::result(exitCode:127)]);
    $version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true)['version'];$zip=$root.'/.build/ibyte-minecraft-manager-v'.$version.'.zip';
    $service=app(App\Services\Helpers\PluginService::class);$id=$service->downloadPluginFromFile(new Illuminate\Http\UploadedFile($zip,basename($zip),'application/zip',null,true));
    App\Models\Plugin::refreshRows();$service->installPlugin(App\Models\Plugin::findOrFail($id));echo "PASS native import/install; optional asset build stubbed for requested small check.\n";exit;
}
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Operations\CheckReview;
use IByte\MinecraftManager\Models\Operation;
use Illuminate\Support\Facades\Http;
$owner=App\Models\User::factory()->create();$egg=App\Models\Egg::factory()->create(['docker_images'=>['Java 21'=>'approved/java_21']]);
$server=App\Models\Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id,'image'=>'approved/java_21']);
IByte\MinecraftManager\Models\Profile::create(['egg_id'=>$egg->id,'name'=>'Direct smoke','software'=>'FABRIC','loader'=>'fabric','content_type'=>'mod','images'=>['approved/java_21'],'jar_path'=>'server.jar','enabled'=>true]);
app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled'=>true,'providers'=>['modrinth','mcjars']]);
$http=new FixtureTransport([]);$app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class,$http);
$payloads=['https://cdn.modrinth.com/simple.jar'=>'new-mod','https://versions.mcjars.app/fabric.jar'=>'launcher'];
$fixture=function(string $project,string $id,string $name,string $url,string $bytes,string $type='mod')use($http):void{
    $http->responses['modrinth/v2/project/'.$project]=['id'=>$project,'title'=>$project==='simple'?'Simple Config Library':'Small Pack','project_type'=>$type,'server_side'=>'required'];
    $http->responses['modrinth/v2/version/'.$id]=['id'=>$id,'project_id'=>$project,'name'=>$id,'version_number'=>$id,'version_type'=>'release','game_versions'=>['1.21.1'],'loaders'=>['fabric'],'dependencies'=>[],
        'files'=>[['filename'=>$name,'url'=>$url,'size'=>strlen($bytes),'primary'=>true,'hashes'=>['sha512'=>hash('sha512',$bytes)]]]];
};
$fixture('simple','simple1','simple.jar','https://cdn.modrinth.com/simple.jar','new-mod');
$manifest=['formatVersion'=>1,'game'=>'minecraft','versionId'=>'pack1','dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16.10'],
    'files'=>[['path'=>'mods/pack.jar','fileSize'=>7,'hashes'=>['sha512'=>hash('sha512','new-mod')],'downloads'=>['https://cdn.modrinth.com/simple.jar'],'env'=>['server'=>'required']]]];
$zipPath=tempnam(sys_get_temp_dir(),'direct-smoke');$zip=new ZipArchive;$zip->open($zipPath,ZipArchive::OVERWRITE);
foreach(['modrinth.index.json'=>json_encode($manifest),'overrides/config/keep.toml'=>'pack-config','overrides/config/new.toml'=>'new-config'] as $path=>$bytes){$zip->addFromString($path,$bytes);}
$zip->close();$payloads['https://cdn.modrinth.com/pack.mrpack']=file_get_contents($zipPath);unlink($zipPath);
$fixture('pack','pack1','pack.mrpack','https://cdn.modrinth.com/pack.mrpack',$payloads['https://cdn.modrinth.com/pack.mrpack'],'modpack');
$build=['uuid'=>'fabric-build','type'=>'FABRIC','version_id'=>'1.21.1','project_version_id'=>'0.16.10','installation'=>[[['type'=>'download','url'=>'https://versions.mcjars.app/fabric.jar','file'=>'server.jar','size'=>8]]]];
$http->responses['mcjars/api/v3/builds/fabric-build']=['build'=>$build,'version'=>['java'=>21,'type'=>'RELEASE']];
$http->responses['mcjars/api/v3/builds/types/FABRIC/versions/1.21.1']=['builds'=>['data'=>[$build],'total'=>1]];
$files=['mods/simple.jar'=>'previous-mod','config/keep.toml'=>'operator-config','world/level.dat'=>'world'];$folders=['mods'=>true,'config'=>true,'world'=>true];$reads=[];$pulls=0;$fail=false;$onPull=null;
$mkdir=function(string $path)use(&$folders):void{for($p=trim($path,'/');$p!=='.'&&$p!=='';$p=dirname($p)){$folders[$p]=true;}};
Http::swap(new Illuminate\Http\Client\Factory);
Http::fake(function($request)use(&$files,&$folders,&$reads,&$pulls,&$fail,&$onPull,$payloads,$mkdir){
    $url=parse_url($request->url());parse_str($url['query']??'',$query);$path=$url['path'];$headers=['User-Agent'=>'Pelican Wings/v1.0.0'];
    if(str_ends_with($path,'/files/pull')){
        check($request['foreground']===true,'Pull must wait for native completion');++$pulls;
        $target=trim($request['root'].'/'.$request['file_name'],'/');$files[$target]=$payloads[$request['url']];$mkdir(dirname($target));
        if($onPull){$onPull();}
        if($fail){return Http::response(['error'=>'injected'],503,$headers);}
        return Http::response(['name'=>basename($target),'file'=>true,'directory'=>false,'symlink'=>false,'size'=>strlen($files[$target])],200,$headers);
    }
    if(str_ends_with($path,'/files/list-directory')){
        $dir=trim($query['directory']??'','/');if($dir!==''&&!isset($folders[$dir])){return Http::response([],404,$headers);}$rows=[];
        foreach([...array_keys($folders),...array_keys($files)] as $p){if(dirname($p)!==($dir===''?'.':$dir)){continue;}$isDir=isset($folders[$p]);$rows[]=['name'=>basename($p),'file'=>!$isDir,'directory'=>$isDir,'symlink'=>false,'size'=>isset($files[$p])?strlen($files[$p]):0];}
        return Http::response($rows,200,$headers);
    }
    if(str_ends_with($path,'/files/create-directory')){$mkdir(trim($request['path'].'/'.$request['name'],'/'));return Http::response('',204,$headers);}
    if(str_ends_with($path,'/files/rename')){foreach($request['files'] as $move){check(isset($files[$move['from']])&&!isset($files[$move['to']]),'Invalid native move');$files[$move['to']]=$files[$move['from']];unset($files[$move['from']]);$mkdir(dirname($move['to']));}return Http::response('',204,$headers);}
    if(str_ends_with($path,'/files/delete')){foreach($request['files'] as $p){foreach(array_keys($files) as $f){if($f===$p||str_starts_with($f,$p.'/')){unset($files[$f]);}}foreach(array_keys($folders) as $f){if($f===$p||str_starts_with($f,$p.'/')){unset($folders[$f]);}}}return Http::response('',204,$headers);}
    if(str_ends_with($path,'/files/decompress')){
        $dir=trim($request['root'],'/');$p=trim($dir.'/'.$request['file'],'/');$tmp=tempnam(sys_get_temp_dir(),'wings-sim');file_put_contents($tmp,$files[$p]);$z=new ZipArchive;check($z->open($tmp)===true);
        for($i=0;$i<$z->numFiles;$i++){$name=$z->getNameIndex($i);$dest=$dir.'/'.$name;$files[$dest]=$z->getFromIndex($i);$mkdir(dirname($dest));}$z->close();unlink($tmp);return Http::response('',204,$headers);
    }
    if(str_ends_with($path,'/files/contents')){$p=trim($query['file'],'/');$reads[]=$p;check(str_ends_with($p,'/modrinth.index.json'),'Unexpected Panel payload read: '.$p);return Http::response($files[$p],200,['Content-Length'=>(string)strlen($files[$p]),...$headers]);}
    if(str_ends_with($path,'/files/write')){throw new RuntimeException('Panel payload uploads are forbidden');}
    return Http::response(['state'=>'offline'],200,$headers);
});
$passed=0;$check=function(bool $ok,string $name)use(&$passed):void{check($ok,$name);++$passed;echo "PASS $name\n";};
$install=function(string $kind,string $project,string $release)use($owner,$server):Operation{
    $service=app(ReviewService::class);$op=$service->create($owner,$server->fresh(),['kind'=>$kind,'provider'=>'modrinth','project'=>$project,'release'=>$release,'version'=>'1.21.1','loader'=>'fabric','type'=>$kind==='pack'?'modpack':'mod']);
    $service->queue($owner,$server->fresh(),$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);return $op->fresh();
};
$op=$install('package','simple','simple1');$check($op->status==='succeeded'&&$files['mods/simple.jar']==='new-mod'&&$reads===[]&&$op->server_slot===null,'mod replaces its target using foreground native pulls with zero Panel payload reads');
$meta=json_decode(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/simple.jar')->value('metadata'),true);
$check($meta['integrity']==='size-checked'&&!isset($meta['sha256']),'inventory distinguishes expected provider hashes from measured checksums');
$op=$install('pack','pack','pack1');$check($op->status==='succeeded'&&$files['server.jar']==='launcher'&&$files['mods/pack.jar']==='new-mod'&&$files['config/keep.toml']==='operator-config'&&$files['config/new.toml']==='new-config'&&$files['world/level.dat']==='world'&&count($reads)===1,'small pack installs exact runtime, preserves config/world and reads only manifest JSON on Panel: '.($op->error_code??$op->status));
$fail=true;$op=$install('package','simple','simple1');$fail=false;$check($op->status==='failed'&&$op->server_slot===null&&$op->step==='partial-files-kept'&&$files['mods/simple.jar']==='new-mod','download failure releases the slot without invoking rollback');
$onPull=function()use(&$onPull,$owner,$server):void{$onPull=null;$op=Operation::where('server_slot',$server->id)->firstOrFail();app(ReviewService::class)->cancel($owner,$server->fresh(),$op->id);};
$op=$install('package','simple','simple1');$check($op->status==='cancelled'&&$op->server_slot===null,'cancellation settles current pulls, keeps applied files and releases the slot');
$service=app(ReviewService::class);$interrupted=$service->create($owner,$server->fresh(),['kind'=>'package','provider'=>'modrinth','project'=>'simple','release'=>'simple1','version'=>'1.21.1','loader'=>'fabric','type'=>'mod']);
$plan=$interrupted->plan;$plan['direct_install']=true;$plan['direct_files']=['mods/simple.jar'];
$interrupted->update(['status'=>'applying','server_slot'=>$server->id,'plan'=>$plan]);
Illuminate\Support\Facades\DB::table('imm_inventory')->updateOrInsert(['server_id'=>$server->id,'path'=>'mods/unrelated.jar'],['metadata'=>'{}','created_at'=>now(),'updated_at'=>now()]);
(new CheckReview($interrupted->id,true))->failed(new RuntimeException('Interrupted smoke worker'));
$check($interrupted->fresh()->status==='failed'&&$interrupted->fresh()->server_slot===null&&!Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/simple.jar')->exists()&&Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/unrelated.jar')->exists()&&$files['mods/simple.jar']==='new-mod','shared worker failure cleanup drops only uncertain inventory and keeps files');
Illuminate\Support\Facades\DB::table('imm_inventory')->insert(['server_id'=>$server->id,'path'=>'mods/simple.jar','metadata'=>'{}','created_at'=>now(),'updated_at'=>now()]);
(new CheckReview($interrupted->id,true))->failed(new RuntimeException('Late duplicate worker callback'));
$check(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/simple.jar')->exists(),'late worker callbacks cannot invalidate a later installation');
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));
auth()->login($owner);Filament\Facades\Filament::setTenant($server);
$page=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class)->call('requestInstall','queue')->assertSee('Before you install')->assertSee('not rolled back')->assertDispatched('open-modal',id:'imm-backup-reminder');
$page->call('clearInstallPrompt')->assertSet('pendingInstall',[]);$check(true,'native Panel backup reminder opens without starting an installation');
$check(!array_filter(array_keys($files),static fn($p)=>str_contains($p,'.download-')),'operation temporary files and extraction folders are cleaned up');
Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid])->assertSee('Simple Config Library')->assertSee('simple1');
$check(true,'native progress/history render the project name alongside its release title');
$timings=Operation::where('server_id',$server->id)->where('kind','pack')->where('status','succeeded')->latest()->firstOrFail()->plan['progress']['phase_seconds'];
$check(isset($timings['extract'],$timings['scan'],$timings['directories'],$timings['apply'],$timings['startup'],$timings['existing'],$timings['result']),'real install records separate extraction, traversal, directories, moves, startup and size-check phases');
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];
file_put_contents($root.'/docs/evidence/direct-smoke-'.str_replace('0.1.0-','',$version).'.json',json_encode(['passed'=>$passed,'native_pulls'=>$pulls,'panel_reads'=>$reads,'scope'=>'Small deterministic native Panel/Livewire/HTTP-contract smoke only; no real Wings, provider throughput, concurrency benchmark, browser or game boot'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "RESULT $passed direct-install smoke checks passed.\n";
