<?php
declare(strict_types=1);
if (PHP_OS_FAMILY!=='Linux' || getenv('DB_DATABASE')!=='imm-acceptance.sqlite') { throw new RuntimeException('Isolated Linux database required'); }
require '/opt/imm-panel/vendor/autoload.php';
$app=require '/opt/imm-panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\Helpers\PluginService::class)->enablePlugin(App\Models\Plugin::findOrFail('ibyte-minecraft-manager'));
$app->register(IByte\MinecraftManager\Providers\MinecraftManagerProvider::class);
use App\Models\{Node,Egg,Server,User,Allocation};
use IByte\MinecraftManager\Models\Profile;
$file='/opt/imm/fixture.json';
if(is_file($file)){echo "Existing isolated fixture retained.\n";exit;}
Illuminate\Support\Facades\DB::table('jobs')->delete();
Illuminate\Support\Facades\DB::table('failed_jobs')->delete();
$node=Node::factory()->create(['name'=>'Minecraft acceptance only','fqdn'=>'127.0.0.1','daemon_listen'=>18080,'daemon_connect'=>18080,
    'daemon_sftp'=>12022,'daemon_base'=>'/opt/imm/volumes','memory'=>24576,'disk'=>65536]);
$egg=Egg::factory()->create(['name'=>'Minecraft acceptance only','docker_images'=>['Java 17'=>'ghcr.io/pelican-eggs/yolks:java_17','Java 21'=>'ghcr.io/pelican-eggs/yolks:java_21','Java 25'=>'ghcr.io/pelican-eggs/yolks:java_25'],
    'config_startup'=>json_encode(['done'=>'Done (']), 'config_stop'=>'stop','config_files'=>'{}', 'features'=>[],
    'startup_commands'=>['Default'=>'java -Xms128M -XX:MaxRAMPercentage=85 -jar server.jar nogui']]);
$customStartup='java -Xms128M -Xmx$(({{SERVER_MEMORY}}*{{MAXIMUM_MEMORY}}/100))M {{JVM_ARGS}} -jar {{SERVER_JARFILE}} nogui';
$egg->update(['startup_commands'=>['Default'=>$customStartup]]);
foreach(['SERVER_TYPE'=>'neoforge','VERSION'=>'1.21.1','BUILDS'=>'21.1.244','JVM_ARGS'=>'-Dimm.fixture=true','MAXIMUM_MEMORY'=>'85','SERVER_JARFILE'=>'old.jar'] as $key=>$value){
    App\Models\EggVariable::factory()->create(['egg_id'=>$egg->id,'name'=>$key,'env_variable'=>$key,'default_value'=>$value,'rules'=>'required|string','user_viewable'=>true,'user_editable'=>false]);
}
$owner=User::factory()->create();
Profile::create(['egg_id'=>$egg->id,'name'=>'Acceptance','enabled'=>true,'jar_path'=>'server.jar','software'=>'UNKNOWN','loader'=>'','content_type'=>'mod','images'=>[]]);
$cases=[];
foreach(['cobblemon'=>['modrinth','5FFgwNNP','BpTjgzL8'], 'nexus'=>['curseforge','454095','6765239'],
    'odyssey'=>['curseforge','434113','6709034'], 'fabric1211'=>['mcjars','FABRIC','1.21.1'],
    'vanilla1201'=>['mcjars','VANILLA','1.20.1'], 'paper1214'=>['mcjars','PAPER','1.21.4'],
    'forge1201'=>['mcjars','FORGE','1.20.1'], 'neoforge1211'=>['mcjars','NEOFORGE','1.21.1'],
    'quilt1201'=>['mcjars','QUILT','1.20.1'], 'vanilla263'=>['mcjars','VANILLA','26.3'], 'neoforge263'=>['mcjars','NEOFORGE','26.3']] as $index=>$case){
    $port=25661+count($cases);
    $allocation=Allocation::factory()->create(['node_id'=>$node->id,'ip'=>'127.0.0.1','port'=>$port]);
    $server=Server::factory()->create(['node_id'=>$node->id,'allocation_id'=>$allocation->id,'egg_id'=>$egg->id,'owner_id'=>$owner->id,
        'name'=>'Acceptance '.$index,'memory'=>in_array($index,['nexus','odyssey'],true)?12288:8192,'disk'=>16384,'swap'=>0,'skip_scripts'=>true,
        'startup'=>$customStartup,'image'=>'ghcr.io/pelican-eggs/yolks:java_21']);
    $allocation->update(['server_id'=>$server->id]);
    $cases[$index]=['id'=>$server->id,'uuid'=>$server->uuid,'port'=>$port,'provider'=>$case[0],'project'=>$case[1],'release'=>$case[2]];
}
app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled'=>true,'experimental'=>true,'providers'=>['mcjars','modrinth','curseforge']]);
$config=$node->getConfiguration();
$config['api']['host']='127.0.0.1';
$config['system']+=['root_directory'=>'/opt/imm/wings','log_directory'=>'/opt/imm/logs','archive_directory'=>'/opt/imm/archives','backup_directory'=>'/opt/imm/backups','tmp_directory'=>'/opt/imm/tmp'];
$config['system']['sftp']['bind_address']='127.0.0.1';
$config['docker']=['network'=>['name'=>'host','driver'=>'host','network_mode'=>'host','interface'=>'127.0.0.1','enable_icc'=>true,'is_internal'=>false,'ipv6'=>false]];
file_put_contents('/opt/imm/wings.yml',Symfony\Component\Yaml\Yaml::dump($config,8,2,Symfony\Component\Yaml\Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));chmod('/opt/imm/wings.yml',0600);
file_put_contents($file,json_encode(['node_id'=>$node->id,'owner_id'=>$owner->id,'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo count($cases)," isolated native servers and official Wings configuration created.\n";
