<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'){throw new RuntimeException('Linux acceptance only');}
require_once __DIR__.'/evidence.php';
$baseline=json_decode(file_get_contents('/opt/imm/neoforge1211-installation.json'),true);
if($baseline['status']!=='succeeded'||$baseline['runtime']['version']!=='1.21.1'){throw new RuntimeException('Successful baseline required');}
$baseline['version']='0.1.0-preview.17';$baseline['latency_ms_per_node_request']=50;
$baseline['zip_sha256']=hash_file('sha256',dirname(__DIR__,2).'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.17.zip');
file_put_contents(acceptanceEvidencePath('baseline17-neoforge1211.json'),json_encode($baseline,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);
$case=$fixture['cases']['quilt1201'];
if(is_dir('/opt/imm/volumes/'.$case['uuid'].'/libraries')){throw new RuntimeException('Clean comparison volume required');}
$fixture['cases']['neoforge1211fast']=[...$case,'provider'=>'mcjars','project'=>'NEOFORGE','release'=>'1.21.1'];
file_put_contents('/opt/imm/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "Baseline preserved; clean comparison server ready.\n";
