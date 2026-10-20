<?php
putenv('DB_DATABASE=imm-acceptance.sqlite');putenv('APP_URL=http://127.0.0.1:18096');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$f=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$case=$f['cases']['paper1214'];$s=App\Models\Server::findOrFail($case['id']);
$op=IByte\MinecraftManager\Models\Operation::where('server_id',$s->id)->latest()->first();
echo json_encode(['current'=>$s->only(['startup','image','skip_scripts']),'previous'=>$op->plan['previous_startup'],'journal'=>$op->plan['journal']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
try{$new=app(App\Services\Servers\StartupModificationService::class)->setUserLevel(App\Models\User::USER_LEVEL_ADMIN)->handle($s,$op->plan['previous_startup']);app(App\Repositories\Daemon\DaemonServerRepository::class)->setServer($new)->sync();echo "Native startup/sync pass\n";}catch(Throwable $e){echo get_class($e),': ',$e->getMessage(),"\n";}
