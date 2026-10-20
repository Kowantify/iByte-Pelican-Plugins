<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/transfer-probe.php';TransferProbe::start($app);
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$server=App\Models\Server::findOrFail($fixture['cases']['paper1214']['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);$node->assertStopped();
$before=[];foreach(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->get() as $row){$before[$row->path]=$node->hash($row->path);}
$world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$worldHash=hash_file('sha256',$world);
$reviews=app(IByte\MinecraftManager\Operations\ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'1u6JkXh5','release'=>'YFnqiUlL','type'=>'plugin','version'=>'1.21.4','loader'=>'paper']);
$reviews->queue($owner,$server,$op->id,true);$app->call([new IByte\MinecraftManager\Operations\CheckReview($op->id,true),'handle']);$op->refresh();
$unchanged=true;foreach($before as $path=>$hash){$unchanged=$unchanged&&$node->hash($path)===$hash;}
$result=['scope'=>'Actual WorldEdit 7.4.6 artifact, native Paper 1.21.4 Java 21 fixture and production remote-download preflight; no provider/node mocks','status'=>$op->status,'error'=>$op->error_code,'failure'=>$op->plan['failure']??null,'files_unchanged'=>$unchanged,'world_unchanged'=>hash_file('sha256',$world)===$worldHash,'reservation_released'=>$op->server_slot===null,'transfer'=>TransferProbe::result(),'recorded_at'=>gmdate('c')];
$result['passed']=$op->status==='failed'&&$op->error_code==='plugin_java_incompatible'&&$unchanged&&$result['world_unchanged']&&$result['reservation_released'];
file_put_contents('/opt/imm/live-plugin-java.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result),"\n";exit($result['passed']?0:1);
