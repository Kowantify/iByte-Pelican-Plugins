<?php
declare(strict_types=1);
// Test fixture reset only. Never packaged or invoked by product code.
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/evidence.php';
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$case=$fixture['cases']['cobblemon'];$server=App\Models\Server::findOrFail($case['id']);
if($server->uuid!==$case['uuid']||$server->name!=='Acceptance cobblemon'||$server->node_id!==$fixture['node_id']||$server->node->fqdn!=='127.0.0.1'||$server->node->daemon_listen!==18080){throw new RuntimeException('Refusing to reset an unrecognized server');}
if(IByte\MinecraftManager\Models\Operation::whereIn('server_id',array_column($fixture['cases'],'id'))->whereNotNull('server_slot')->exists()){throw new RuntimeException('An installation is still active');}
$node=new IByte\MinecraftManager\Operations\NodeFiles($server);$node->assertStopped();
$previous='/opt/imm/cobblemon-installation.json';if(is_file($previous)){copy($previous,acceptanceEvidencePath('cobblemon-before-journal-optimization.json'));}
$repository=app(App\Repositories\Daemon\DaemonFileRepository::class)->setServer($server);$names=[];
foreach($repository->getDirectory('/') as $entry){$names[]=IByte\MinecraftManager\Support\Input::filename($entry['name']);}
if($names){$repository->deleteFiles('/',$names)->throw();}
if($repository->getDirectory('/')!==[]){throw new RuntimeException('Test volume was not reset');}
Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->delete();IByte\MinecraftManager\Models\Setting::whereKey('runtime-'.$server->id)->delete();
echo "Reset only the stopped, disposable Cobblemon fixture for a clean final-code installation.\n";
