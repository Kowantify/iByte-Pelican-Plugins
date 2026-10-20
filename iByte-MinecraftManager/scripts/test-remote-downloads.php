<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app=require $root.'/.research/panel/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use IByte\MinecraftManager\Operations\NodeFiles;
use IByte\MinecraftManager\Support\Failure;
use Illuminate\Support\Facades\Http;
$server=App\Models\Server::factory()->create(); $passed=0;
function remoteTest(string $name,callable $test):void { global $passed; $test(); ++$passed; echo "PASS $name\n"; }
function remoteFixture(App\Models\Server $server, bool $fail=false, bool $external=false):array {
    $state=(object)['active'=>[], 'files'=>[], 'dirs'=>[], 'max'=>0, 'pulls'=>0, 'writes'=>0, 'deleted'=>[], 'mkdir'=>0, 'fail'=>$fail, 'external'=>$external];
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(function($request)use($state){
        check($request->hasHeader('Authorization')); $path=parse_url($request->url(),PHP_URL_PATH); parse_str(parse_url($request->url(),PHP_URL_QUERY)??'', $query);
        $clean=static fn($value)=>trim($value,'/');
        if(str_ends_with($path,'/files/pull') && $request->method()==='GET'){
            $rows=$state->external?[['Identifier'=>'external-download','Progress'=>.2]]:[];
            foreach($state->active as $id=>&$item){
                $item['tick']++;
                if($item['tick']>=3){ if(!$state->fail){$state->files[$item['path']]='test-payload';} unset($state->active[$id]); }
                else{$rows[]=['Identifier'=>$id,'Progress'=>$item['tick']/3];}
            } unset($item);
            return Http::response(['downloads'=>$rows]);
        }
        if(str_ends_with($path,'/files/pull') && $request->method()==='POST'){
            check($request['foreground']===false && !($request['use_header']??false));
            check(str_starts_with($request['url'],'https://cdn.modrinth.com/'));
            $id=(string)Illuminate\Support\Str::uuid(); $state->active[$id]=['path'=>$clean($request['root'].'/'.$request['file_name']),'tick'=>0];
            ++$state->pulls; $state->max=max($state->max,count($state->active));
            return Http::response(['identifier'=>$id],202);
        }
        if($request->method()==='DELETE' && str_contains($path,'/files/pull/')){ $id=basename($path); $state->deleted[]=$id; unset($state->active[$id]); return Http::response('',204); }
        if(str_ends_with($path,'/files/list-directory')){
            $directory=$clean($query['directory']??'/'); $rows=[];
            foreach([...array_keys($state->dirs),...array_keys($state->files)] as $entry){
                if(dirname($entry)!==($directory?:'.')){continue;}
                $dir=isset($state->dirs[$entry]);$rows[]=['name'=>basename($entry),'directory'=>$dir,'file'=>!$dir,'symlink'=>false,'size'=>$dir?0:strlen($state->files[$entry])];
            } return Http::response($rows);
        }
        if(str_ends_with($path,'/files/create-directory')){ $state->dirs[$clean($request['path'].'/'.$request['name'])]=true; ++$state->mkdir; return Http::response('',204); }
        if(str_ends_with($path,'/files/write')){ ++$state->writes; throw new RuntimeException('Unexpected Panel upload'); }
        if(str_ends_with($path,'/files/contents')){ $bytes=$state->files[$clean($query['file'])]??''; return Http::response($bytes,200,['Content-Length'=>(string)strlen($bytes)]); }
        throw new RuntimeException('Unexpected native call: '.$request->method().' '.$path);
    });
    return [new NodeFiles($server),$state];
}
function remoteItems(int $count):array { $items=[];for($i=0;$i<$count;$i++){$items[]=['url'=>'https://cdn.modrinth.com/'.$i.'.jar','path'=>'private/stage/'.$i.'.jar','size'=>12,'name'=>$i.'.jar'];}return $items; }
remoteTest('three native downloads run concurrently; fractions come from Wings; no uploads',function()use($server){
    [$node,$state]=remoteFixture($server);$progress=[];$node->pullMany(remoteItems(7),function($value)use(&$progress){$progress[]=$value;});
    check($state->pulls===7 && $state->max===3 && $state->writes===0 && count($state->files)===7 && !$state->active);
    check(count(array_filter($progress,fn($value)=>$value['fraction']>0 && $value['fraction']<1))>0);
    check(end($progress)['files']===7 && end($progress)['fraction']==1 && !$state->deleted);
});
remoteTest('created directory chains are reused within the operation',function()use($server){[$node,$state]=remoteFixture($server);$node->mkdir('private/stage');$node->mkdir('private/stage');check($state->mkdir===2);});
remoteTest('disappearing failed downloads do not count as success; remaining owned requests are cancelled',function()use($server){
    [$node,$state]=remoteFixture($server,true);rejects(fn()=>$node->pullMany(remoteItems(3),fn($state)=>null),'artifact_download_failed');check(count($state->deleted)===3);
});
remoteTest('authorization failure cancels owned downloads without cancelling another user download',function()use($server){
    [$node,$state]=remoteFixture($server,false,true);
    rejects(fn()=>$node->pullMany(remoteItems(3),function(){throw new Failure('permission_denied');}),'permission_denied');
    check($state->max===2 && count($state->deleted)===2 && !in_array('external-download',$state->deleted,true));
});
remoteTest('unapproved remote URLs cannot reach Wings',function()use($server){[$node,$state]=remoteFixture($server);$items=remoteItems(1);$items[0]['url']='https://evil.example/payload.jar';rejects(fn()=>$node->pullMany($items,fn()=>null),'unapproved_host');check($state->pulls===0);});
remoteTest('one streamed read verifies publisher SHA-512 and produces the durable SHA-256',function()use($server){
    [$node,$state]=remoteFixture($server);$state->files['private/file.jar']='test-payload';$result=$node->verifiedRead('private/file.jar',12,['sha512'=>hash('sha512','test-payload')]);check($result['sha256']===hash('sha256','test-payload') && $result['size']===12);
});
remoteTest('publisher checksum mismatch refuses downloaded bytes',function()use($server){[$node,$state]=remoteFixture($server);$state->files['private/file.jar']='test-payload';rejects(fn()=>$node->verifiedRead('private/file.jar',12,['sha1'=>str_repeat('a',40)]),'hash_mismatch');});
remoteTest('truncated and oversized node responses refuse downloaded bytes',function()use($server){[$node,$state]=remoteFixture($server);$state->files['private/file.jar']='test-payload';rejects(fn()=>$node->verifiedRead('private/file.jar',13),'size_mismatch');rejects(fn()=>$node->verifiedRead('private/file.jar',11),'artifact_size_limit');});
remoteTest('unknown and malformed publisher hashes cannot silently weaken integrity',function()use($server){[$node]=remoteFixture($server);rejects(fn()=>$node->verifiedRead('private/file.jar',12,['md5'=>str_repeat('a',32)]),'weak_integrity_policy');rejects(fn()=>$node->verifiedRead('private/file.jar',12,['sha512'=>'bad']),'invalid_hash');});
file_put_contents($root.'/docs/evidence/remote-downloads.json',json_encode(['passed'=>$passed,'scope'=>'Actual native NodeFiles/DaemonFileRepository with deterministic asynchronous Wings HTTP contract; no live node'],JSON_PRETTY_PRINT)."\n");
echo "RESULT $passed remote-download checks passed.\n";
