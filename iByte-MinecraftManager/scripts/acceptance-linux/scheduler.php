<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'){exit(1);}putenv('DB_DATABASE=imm-acceptance.sqlite');putenv('APP_URL=http://127.0.0.1:18096');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$case=$fixture['cases']['fabric1211'];$server=App\Models\Server::findOrFail($case['id']);$owner=App\Models\User::findOrFail($fixture['owner_id']);
if(IByte\MinecraftManager\Models\Operation::whereIn('server_id',array_column($fixture['cases'],'id'))->whereNotNull('server_slot')->exists()){throw new RuntimeException('An acceptance installation is still active; test deliveries cannot be cleared');}
// Previous cases invoked handlers directly and left their completed serialized
// deliveries in this disposable queue. Retain every operation/journal, clear
// only those test queue deliveries before measuring one fresh real dispatch.
Illuminate\Support\Facades\DB::table('jobs')->where('queue','minecraft-manager')->delete();
$world='/opt/imm/volumes/'.$server->uuid.'/world/level.dat';$before=hash_file('sha256',$world);
$reviews=app(IByte\MinecraftManager\Operations\ReviewService::class);
$op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'uXXizFIs','release'=>'sOzRw3CG','version'=>'1.21.1','loader'=>'fabric','type'=>'mod']);
$reviews->queue($owner,$server,$op->id,true);
$event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='minecraft-manager-installs')??throw new RuntimeException('Scheduler event missing');
$result=['scope'=>'Official Panel RC.1 core, actual native background scheduler and fresh queue worker, real Modrinth artifact and official Wings RC.1; no provider/download/node mocks','operation'=>$op->id,'started_at'=>gmdate('c'),'plugin_version'=>App\Models\Plugin::findOrFail('ibyte-minecraft-manager')->version];
if(!$event->filtersPass($app)){throw new RuntimeException('Scheduler filters refused pending job');}$event->run($app);
for($i=0;$i<180;$i++){sleep(1);$op->refresh();if(!in_array($op->status,['queued','preflight','downloading','verifying','applying','verifying-result'],true)){break;}}
$result+=['status'=>$op->status,'step'=>$op->step,'error'=>$op->error_code,'worker_started'=>IByte\MinecraftManager\Models\Setting::find('install-worker')?->value['last_started_at']??null,'reservation_released'=>$op->server_slot===null,'world_unchanged'=>hash_file('sha256',$world)===$before];
// Keep this WSL invocation alive until its background child and schedule:finish
// exit. Closing the WSL invocation at the first completed job can terminate its
// remaining process group before Laravel releases the scheduled-event mutex.
for($i=0;$i<20&&$event->mutex->exists($event);$i++){sleep(1);}
$result['scheduler_mutex_released']=!$event->mutex->exists($event);
if($op->status==='succeeded'){$node=app(IByte\MinecraftManager\Operations\NodeFiles::class,['server'=>$server]);foreach($op->plan['installed_files'] as $path=>$entry){if($entry['new']!==null&&$node->hash($path)!==$entry['new']){throw new RuntimeException('Final readback mismatch');}}$result['readback_verified']=true;}
$result['finished_at']=gmdate('c');file_put_contents('/opt/imm/live-scheduler.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result,JSON_UNESCAPED_SLASHES),"\n";exit($op->status==='succeeded'&&$result['reservation_released']&&$result['world_unchanged']?0:1);
