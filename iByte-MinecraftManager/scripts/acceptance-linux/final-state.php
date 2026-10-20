<?php
declare(strict_types=1);
require_once __DIR__.'/evidence.php';
putenv('DB_DATABASE=imm-acceptance.sqlite');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$ids=array_column($fixture['cases'],'id');
$active=IByte\MinecraftManager\Models\Operation::whereIn('server_id',$ids)->whereNotNull('server_slot')->count();$event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='minecraft-manager-installs');
$result=['owned_server_reservations'=>$active,'scheduler_mutex_held'=>$event->mutex->exists($event),'temporary_curseforge_credential_present'=>app(IByte\MinecraftManager\Support\Settings::class)->credential('curseforge')!==null,'recorded_at'=>gmdate('c')];
$target=acceptanceEvidencePath('final-state.json');file_put_contents($target,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result),"\n";exit($active===0&&!$result['scheduler_mutex_held']&&!$result['temporary_curseforge_credential_present']?0:1);
