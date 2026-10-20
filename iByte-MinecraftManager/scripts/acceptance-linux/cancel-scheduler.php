<?php
putenv('DB_DATABASE=imm-acceptance.sqlite');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$f=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$server=App\Models\Server::findOrFail($f['cases']['fabric1211']['id']);$owner=App\Models\User::findOrFail($f['owner_id']);
foreach(IByte\MinecraftManager\Models\Operation::where('server_id',$server->id)->where('status','queued')->get() as $op){app(IByte\MinecraftManager\Operations\ReviewService::class)->cancel($owner,$server,$op->id);echo 'Cancelled pending isolated scheduler probe: ',$op->id,"\n";}
