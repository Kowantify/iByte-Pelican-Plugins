<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use IByte\MinecraftManager\Operations\NodeFiles;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Http;

$owner = App\Models\User::factory()->create();
$egg = App\Models\Egg::factory()->create();
$server = App\Models\Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id]);
app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id'=>$egg->id,'enabled'=>true]);
app(Settings::class)->save(['enabled'=>true,'experimental'=>false,'providers'=>['modrinth']]);
$live = in_array('--live', $argv, true);
$bytes = 'native-protocol-mod';
$project = 'protocolmod'; $release = 'protocol-v1'; $version = '1.21.1'; $filename = 'protocol.jar';
if ($live) {
    $transport = app(IByte\MinecraftManager\Catalog\JsonTransport::class);
    $body = $transport->get('modrinth','/v2/version/d5ddUdiB');
    $project=$body['project_id']; $release=$body['id']; $version=$body['game_versions'][0]; $filename=$body['files'][0]['filename'];
    $probe=$root.'/.build/node-live-artifact'; $source=$body['files'][0];
    app(IByte\MinecraftManager\Support\ArtifactDownloader::class)->download($source['url'],$probe,$source['size'],$source['hashes']);
    $bytes=file_get_contents($probe); unlink($probe);
} else {
    $http = new FixtureTransport([
        'modrinth/v2/project/protocolmod'=>['id'=>'protocolmod','project_type'=>'mod','server_side'=>'required'],
        'modrinth/v2/version/protocol-v1'=>['id'=>'protocol-v1','project_id'=>'protocolmod','name'=>'Protocol mod','version_number'=>'1','version_type'=>'release',
            'game_versions'=>['1.21.1'],'loaders'=>['fabric'],'dependencies'=>[],
            'files'=>[['filename'=>$filename,'url'=>'https://cdn.modrinth.com/protocol.jar','primary'=>true,'size'=>strlen($bytes),'hashes'=>['sha512'=>hash('sha512',$bytes)]]]],
    ]);
    $app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class,$http);
    $app->instance(IByte\MinecraftManager\Support\ArtifactDownloader::class,new class extends IByte\MinecraftManager\Support\ArtifactDownloader {
        public function download(string $url,string $destination,?int $size,array $hashes=[]): array {
            $bytes='native-protocol-mod'; check($size===strlen($bytes) && $hashes['sha512']===hash('sha512',$bytes));
            file_put_contents($destination,$bytes); return ['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
        }
    });
}
// Raw Stat JSON matches server/filesystem/stat.go. Do not use Panel File fields.
$folders=['mods'=>true]; $files=['mods/existing.jar'=>'keep-existing']; $symlinks=[]; $writes=0; $pulls=0; $listings=0;
Http::swap(new Illuminate\Http\Client\Factory);
Http::fake(function ($request) use (&$folders,&$files,&$symlinks,&$writes,&$pulls,&$listings,$bytes,$server) {
    check($request->hasHeader('Authorization'),'Missing native node authorization');
    $path=parse_url($request->url(),PHP_URL_PATH); parse_str(parse_url($request->url(),PHP_URL_QUERY)??'', $query);
    $normalize=static fn ($value)=>trim($value,'/');
    $nodeHeaders=['User-Agent'=>'Pelican Wings/v1.0.0 (id:'.$server->node->daemon_token_id.')'];
    if (str_ends_with($path,'/files/pull')) {
        if ($request->method()==='GET') { return Http::response(['downloads'=>[]]); }
        check($request['foreground']===false && str_starts_with($request['file_name'],'.download-') && str_ends_with($request['file_name'],'.part') && $request['root']==='/mods');
        $files[$normalize($request['root'].'/'.$request['file_name'])]=$bytes; ++$pulls;
        return Http::response(['identifier'=>(string)Illuminate\Support\Str::uuid()],202);
    }
    if (str_ends_with($path,'/files/list-directory')) {
        ++$listings;
        $directory=$normalize($query['directory']??'');
        if ($directory!=='' && !isset($folders[$directory])) { return Http::response(['error'=>'not found'],404,$nodeHeaders); }
        $rows=[];
        foreach ([...array_keys($folders),...array_keys($files),...array_keys($symlinks)] as $entry) {
            if (($directory===''?'.':$directory)!==dirname($entry)) { continue; }
            $isDirectory=isset($folders[$entry]);
            $rows[]=['name'=>basename($entry),'directory'=>$isDirectory,'file'=>!$isDirectory,'symlink'=>isset($symlinks[$entry]),'size'=>isset($files[$entry])?strlen($files[$entry]):0];
        }
        return Http::response($rows);
    }
    if (str_ends_with($path,'/files/create-directory')) {
        $target=$normalize(($request['path']??'/').'/'.$request['name']); $folders[$target]=true;
        return Http::response('',204);
    }
    if (str_ends_with($path,'/files/write')) { $files[$normalize($query['file'])]=$request->body(); ++$writes; return Http::response('',204); }
    if (str_ends_with($path,'/files/contents')) {
        if (($query['file']??'')==='/unavailable.jar') { return Http::response('',503,$nodeHeaders); }
        $file=$files[$normalize($query['file'])]??null;
        return $file===null?Http::response('',404,$nodeHeaders):Http::response($file,200,['Content-Length'=>(string)strlen($file)]);
    }
    if (str_ends_with($path,'/files/rename')) {
        foreach ($request['files'] as $move) {
            $from=$normalize(($request['root']??'/').'/'.$move['from']); $to=$normalize(($request['root']??'/').'/'.$move['to']);
            check(isset($files[$from])&&!isset($files[$to]),'Unsafe native rename'); $files[$to]=$files[$from]; unset($files[$from]);
        }
        return Http::response('',204);
    }
    if (str_ends_with($path,'/files/delete')) { foreach ($request['files'] as $file) { unset($files[$normalize($file)], $folders[$normalize($file)]); } return Http::response('',204); }
    return Http::response(['state'=>'offline']);
});
$passed=0;
$assert=static function (bool $success,string $name) use (&$passed): void { check($success,$name); ++$passed; echo "PASS $name\n"; };
$native=new NodeFiles($server);
$beforeListings=$listings;
$entries=$native->entries(['mods/existing.jar','mods/missing.jar']);
$assert($listings===$beforeListings+2 && isset($entries['mods/existing.jar']) && !isset($entries['mods/missing.jar']), 'sibling metadata shares a directory request after validating its parent');
$assert($native->entries(['absent/missing.jar'])===[], 'only missing parent directories are treated as absent metadata');
$assert($native->hashOrNull('mods/missing.jar')===null && $native->hashOrNull('mods/existing.jar')===hash('sha256','keep-existing'), 'hash lookup distinguishes missing files from verified current bytes');
$unavailableRejected=false;
try { $native->hashOrNull('unavailable.jar'); } catch (Illuminate\Http\Client\RequestException $error) { $unavailableRejected=$error->response->status()===503; }
$assert($unavailableRejected, 'node errors propagate instead of being treated as missing files');
$native->mkdir('mods');
$assert(true,'existing Wings directory is accepted with raw directory/file fields');
$files['occupied']='file';
rejects(fn ()=>$native->mkdir('occupied/subfolder'),'node_path_collision');
$assert(true,'a real file occupying a folder path is still refused');
$folders['linked']=true; $symlinks['linked']=true;
rejects(fn ()=>$native->mkdir('linked/subfolder'),'node_path_collision');
$assert(true,'symbolic links cannot be used as install folders');
$scan=app(IByte\MinecraftManager\Packages\Inventory::class)->scan($server);
$assert(count($scan)===1 && $scan[0]['name']==='existing.jar','inventory reads raw Wings file flags');
$service=app(ReviewService::class);
$op=$service->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>$project,'release'=>$release,'version'=>$version,'loader'=>'fabric','type'=>'mod']);
$service->queue($owner,$server,$op->id,true);
// Native worker delivery, full real NodeFiles adapter: no overridden mkdir/upload/move.
Illuminate\Support\Facades\Artisan::call('minecraft-manager:work',['--stop-when-empty'=>true]);
$op->refresh();
$assert($op->status==='succeeded' && $op->step==='installed','real queue worker completes package installation through raw Wings HTTP contract: '.($op->error_code??$op->status));
$assert(isset($files['mods/'.$filename]) && $files['mods/existing.jar']==='keep-existing' && $pulls===1 && $writes===0,'Wings remote pull installs the selected JAR with zero Panel uploads and preserves existing files');
$assert(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/'.$filename)->exists() && $op->server_slot===null,
    'verified native transfer persists inventory and releases reservation');
$downloads=[];for($i=0;$i<12;$i++){$downloads[]=['path'=>'mods/.download-batch-'.$i.'.part','url'=>'https://cdn.modrinth.com/batch.jar','size'=>strlen($bytes)];}
$before=$listings;$states=[];$native->pullMany($downloads,static function($state)use(&$states){$states[]=$state;});$batchedListings=$listings-$before;
$assert($batchedListings===4&&end($states)['files']===12,'12 completions in four polling groups share four fresh folder listings instead of twelve');
$bad=$downloads[0];$bad['size']=strlen($bytes)+1;
rejects(fn()=>$native->pullMany([$bad],static function(){}),'artifact_download_failed');
$assert(true,'batched completion still rejects wrong file sizes');
$zip = new ZipArchive;
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.15.zip';
if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.15.zip';}
check($zip->open($baseline)===true);
foreach (['NodeFiles','InstallWorkspace'] as $class) {
    $source=$zip->getFromName('ibyte-minecraft-manager/src/Operations/'.$class.'.php');
    check(is_string($source));
    $source=str_replace('namespace IByte\\MinecraftManager\\Operations;', 'namespace IByte\\MinecraftManager\\BenchmarkBaseline;', $source);
    $fixture=$root.'/.build/baseline-'.$class.'.php'; file_put_contents($fixture,$source); require $fixture;
}
$zip->close();
$originalFolders=$folders; $originalFiles=$files; $counts=[];
foreach (['preview.15','preview.19'] as $implementation) {
    $folders=$originalFolders; $files=$originalFiles;
    $probe=$op->replicate(); $probe->id=(string)Illuminate\Support\Str::uuid(); $probe->idempotency=hash('sha256',$probe->id); $probe->plan=[]; $probe->status='planned'; $probe->save();
    $adapter=$implementation==='preview.15' ? new IByte\MinecraftManager\BenchmarkBaseline\NodeFiles($server) : new NodeFiles($server);
    $workspace=$implementation==='preview.15' ? new IByte\MinecraftManager\BenchmarkBaseline\InstallWorkspace($adapter,$probe) : new IByte\MinecraftManager\Operations\InstallWorkspace($adapter,$probe);
    $outputs=[];
    for($i=0;$i<102;++$i){$outputs['libraries/net/neoforged/library-'.$i.'/21.1.262/library-'.$i.'.jar']=hash('sha256','library-'.$i);}
    $start=$listings;
    $workspace->reserve($outputs); $workspace->parents(array_keys($outputs));
    foreach($outputs as $path=>$hash){$files[$path]='library-'.array_search($path,array_keys($outputs),true);}
    $workspace->cleanup(false); $workspace->cleanup(true);
    $counts[$implementation]=$listings-$start;
    $assert(count(array_intersect_key($files,$outputs))===102, $implementation.' directory preparation and cleanup preserve all 102 runtime outputs');
    if($implementation==='preview.19'){
        $workspace->rollback(); $workspace->cleanup(true);
        $assert(!array_intersect_key($files,$outputs) && !isset($folders['libraries']), 'rollback removes created runtime files and empty directory tree after successful cleanup');
    }
}
$assert($counts['preview.19'] < $counts['preview.15']*0.5, 'scoped parent preparation and cleanup reduce metadata requests by over half');
file_put_contents($root.'/docs/evidence/directory-performance.json', json_encode(['scope'=>'Identical 102-file Maven-shaped directory preparation and two cleanup passes through native DaemonFileRepository and raw HTTP fixture; excludes downloads, promotion and game startup', 'baseline_zip_sha256'=>hash_file('sha256',$baseline), 'directory_list_requests'=>$counts, 'reduction_percent'=>round(100*(1-$counts['preview.19']/$counts['preview.15']),1), 'network_timing_measured'=>false],JSON_PRETTY_PRINT)."\n");
$result=['passed'=>$passed,'live_provider'=>$live,'provider'=>'modrinth','project'=>$project,'release'=>$release,
    'sha256'=>hash('sha256',$files['mods/'.$filename]),'scope'=>'Actual queue worker and native NodeFiles/DaemonFileRepository; raw Wings-shaped HTTP responses; no live Wings/game startup'];
file_put_contents($root.'/docs/evidence/'.($live?'live-reported-mod':'node-contract').'.json',json_encode($result,JSON_PRETTY_PRINT)."\n");
echo "RESULT $passed native node-contract checks passed.\n";
