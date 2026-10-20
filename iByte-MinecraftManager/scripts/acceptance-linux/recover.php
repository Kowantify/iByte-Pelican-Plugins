<?php
if(PHP_OS_FAMILY!=='Linux'){exit(1);}putenv('DB_DATABASE=imm-acceptance.sqlite');putenv('APP_URL=http://127.0.0.1:18096');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$case=$fixture['cases'][$argv[1]];$server=App\Models\Server::findOrFail($case['id']);
$op=IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('status','needs-reconciliation')->latest()->firstOrFail();
$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);$node->assertStopped();
foreach($op->plan['journal'] as $path=>$entry){$current=$node->exists($path)?$node->hash($path):null;if($current===$entry['old']){continue;}if($current!==$entry['new']){throw new RuntimeException('Concurrent edit retained');}if($current!==null){$node->remove($path);}if($entry['old']!==null){if($node->hash($entry['backup'])!==$entry['old']){throw new RuntimeException('Backup mismatch');}$node->move($entry['backup'],$path);}}
$exit=Illuminate\Support\Facades\Artisan::call('minecraft-manager:reconcile',['operation'=>$op->id,'--worker-stopped'=>true]);echo Illuminate\Support\Facades\Artisan::output();exit($exit);
