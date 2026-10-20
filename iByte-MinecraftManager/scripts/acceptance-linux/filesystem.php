<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/evidence.php';
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$results=[];
foreach($fixture['cases'] as $name=>$case){
    $root='/opt/imm/volumes/'.$case['uuid'];$leftovers=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){if(str_starts_with($file->getFilename(),'.download-')){$leftovers[]=substr($file->getPathname(),strlen($root)+1);}}
    $results[$name]=['manager_directory_present'=>file_exists($root.'/.minecraft-manager'),'temporary_files_remaining'=>$leftovers,'server_jar_present'=>is_file($root.'/server.jar')];
    if($results[$name]['manager_directory_present']||$leftovers||!$results[$name]['server_jar_present']){throw new RuntimeException('Unexpected server layout: '.$name);}
}
$case=$fixture['cases']['fabric1211'];$server=App\Models\Server::findOrFail($case['id']);$node=new IByte\MinecraftManager\Operations\NodeFiles($server);$node->assertStopped();
$source=IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('status','succeeded')->firstOrFail();$history=$source->replicate();$history->id=(string)Illuminate\Support\Str::uuid();$history->idempotency=hash('sha256',random_bytes(32));$history->server_slot=null;
$path='mods/legacy-recovery.jar';$bytes='verified legacy recovery';$backup='.minecraft-manager/backups/'.$history->id.'/'.$path;$plan=['journal'=>[$path=>['old'=>hash('sha256',$bytes),'new'=>null,'backup'=>$backup]],'previous_startup'=>['startup'=>$server->startup,'docker_image'=>$server->image,'skip_scripts'=>$server->skip_scripts]];$history->plan=$plan;$history->save();
$temporary=tempnam('/opt/imm','legacy');file_put_contents($temporary,$bytes);$node->upload($backup,$temporary);$node->upload('.minecraft-manager/operator-keep.txt',$temporary);unlink($temporary);
app(IByte\MinecraftManager\Operations\LegacyWorkspace::class)->migrate($node,$server);
$private=(new IByte\MinecraftManager\Operations\InstallWorkspace($node,$history))->directory().'/'.hash('sha256',$path).'.bin';
$legacy=['private_copy_verified'=>is_file($private)&&hash_file('sha256',$private)===hash('sha256',$bytes),'recorded_node_copy_removed'=>!$node->exists($backup),'unrecorded_file_preserved'=>$node->text('.minecraft-manager/operator-keep.txt')===$bytes,'private_location_recorded'=>($history->fresh()->plan['journal'][$path]['backup_disk']??null)===basename($private)];
$node->remove('.minecraft-manager/operator-keep.txt');app(IByte\MinecraftManager\Operations\LegacyWorkspace::class)->migrate($node,$server);$legacy['empty_legacy_tree_removed']=!$node->exists('.minecraft-manager');
if(in_array(false,$legacy,true)){throw new RuntimeException('Legacy export failed');}unlink($private);rmdir(dirname($private));
$result=['passed'=>true,'cases'=>$results,'legacy_upgrade'=>$legacy,'scope'=>'Actual server volumes after installs, stock Wings API legacy export with a recorded backup and an unrecorded sentinel','recorded_at'=>gmdate('c')];file_put_contents(acceptanceEvidencePath('filesystem.json'),json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo "PASS ten clean server layouts and five legacy migration assertions.\n";
