<?php
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(glob('/proc/[0-9]*/cmdline') as $file){$line=@file_get_contents($file);if(str_contains($line?:'','artisan')&&str_contains($line?:'','minecraft-manager:work')){throw new RuntimeException('An install worker is still running');}}
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$owner=App\Models\User::findOrFail($fixture['owner_id']);
foreach($fixture['cases'] as $case){$server=App\Models\Server::findOrFail($case['id']);foreach(IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('status','queued')->get() as $op){app(IByte\MinecraftManager\Operations\ReviewService::class)->cancel($owner,$server,$op->id);}}
$event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='minecraft-manager-installs');$event->mutex->forget($event);echo "No isolated worker remains; interrupted test invocation lock released and owned pending probes cancelled.\n";
