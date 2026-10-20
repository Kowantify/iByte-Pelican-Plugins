<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function read18(string $path):array{global $root;return json_decode(ltrim(file_get_contents($root.'/'.$path),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);}
function ensure18(bool $value,string $message):void{if(!$value){throw new RuntimeException($message);}}
$version=read18('plugin/plugin.json')['version'];ensure18($version==='0.1.0-preview.18','Wrong version');
$candidate=in_array('--candidate',$argv,true);$artifact=($candidate?'.build/':'release/Panel Plugin/').'ibyte-minecraft-manager-v'.$version.'.zip';
$sha=hash_file('sha256',$root.'/'.$artifact);$provenance=read18('.build/package-provenance.json');ensure18($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;ensure18($zip->open($root.'/'.$artifact)===true,'ZIP missing');
foreach($provenance['inputs'] as $entry){$path=$entry['path'];$source=$path==='LICENSE'?'LICENSE':'plugin/'.$path;
    ensure18(hash_file('sha256',$root.'/'.$source)===$entry['sha256']&&hash('sha256',$zip->getFromName('ibyte-minecraft-manager/'.$path))===$entry['sha256'],'Source/ZIP differs: '.$path);
    if(!in_array($path,['LICENSE','plugin.json'],true)){ensure18(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$path)===$entry['sha256'],'UI installed other bytes: '.$path);}
}
ensure18($zip->numFiles===65,'Unexpected ZIP content count');$zip->close();
foreach(['browser'=>38,'native-ui-install'=>6] as $file=>$count){$data=read18('docs/evidence/'.$file.'.json');ensure18(count($data['tests'])===$count&&!array_filter($data['tests'],fn($t)=>$t['status']!=='pass'),'UI failure: '.$file);}
ensure18(read18('docs/evidence/browser.json')['javascript_exceptions']===0,'JavaScript exception');
ensure18(read18('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha&&read18('docs/evidence/native-ui-install-probe.json')['passed']===5,'Native UI artifact/schema/build mismatch');
foreach(['catalog-access'=>42,'installs'=>55,'node-contract'=>15,'remote-downloads'=>9,'scheduler'=>12] as $file=>$count){ensure18(read18('docs/evidence/'.$file.'.json')['passed']===$count,'Contract check missing: '.$file);}
foreach(['unit'=>52,'native'=>29] as $name=>$count){ensure18(str_contains(file_get_contents($root.'/docs/evidence/'.$name.'-preview.18.log'),'RESULT '.$count),'Unit/lifecycle report missing');}
$prefix='docs/evidence/live-performance18/';$baseline=read18($prefix.'baseline17-neoforge1211.json');$measured=read18($prefix.'neoforge1211fast-installation.json');$direct=read18($prefix.'neoforge1211direct-installation.json');
foreach([$baseline,$measured,$direct] as $case){ensure18($case['status']==='succeeded'&&$case['files']===102&&$case['runtime']['build']===$baseline['runtime']['build']&&$case['server_jar_sha256']===$baseline['server_jar_sha256'],'NeoForge comparison artifact mismatch');}
$seconds=static fn($r)=>strtotime($r['finished_at'])-strtotime($r['started_at']);
ensure18($seconds($measured)<$seconds($baseline)/4,'Speed improvement missing');
$games=[];
foreach(['neoforge1211fast','fabric1211','forge1201','paper1214','cobblemon'] as $name){
    $install=read18($prefix.$name.'-installation.json');$boot=read18($prefix.$name.'-startup.json');
    ensure18($install['status']==='succeeded'&&$install['transfer']['panel_uploads']===0&&$boot['ready']&&$boot['expected_protocol_verified']&&$boot['runtime_variables_in_container_verified'],'Live install/boot failed: '.$name);
    if($name!=='cobblemon'){$mods=read18($prefix.$name.'-packages.json');ensure18($mods['status']==='succeeded'&&$mods['readback_verified']&&$mods['world_unchanged']&&$mods['transfer']['panel_uploads']===0,'Batch failed: '.$name);}
    $games[]=$name;
}
ensure18(read18($prefix.'live-rollback.json')['passed'],'Native bulk-rename recovery failed');
ensure18(read18($prefix.'installed-candidate.json')['sha256']===$sha,'Linux candidate mismatch');
$state=read18($prefix.'final-state.json');ensure18($state['passed']&&count($state['containers'])===5&&$state['active_reservations']===0&&$state['pending_jobs']===0&&$state['credential_absent'],'Dirty node state');
ensure18(read18($prefix.'cleanup.json')['owned_distro_removed']&&read18('docs/evidence/ui-cleanup-preview.18.json')['owned_helpers_stopped'],'Helper cleanup incomplete');
foreach(['plugin','scripts','docs'] as $scope){ensure18(read18('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan failed');}
$old=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.17.zip';if(!is_file($old)){$old=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.17.zip';}
ensure18(hash_file('sha256',$old)==='2506d18a8ef3b81f716b9d76d9b86d8d4badec90da04645ebc41015677ac5458','Delivered preview.17 changed');
$report=['version'=>$version,'artifact'=>$artifact,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$artifact),'packaged_files'=>65,'unit_checks'=>52,'native_checks'=>29,'installer_checks'=>55,'catalog_checks'=>42,'node_checks'=>15,'remote_checks'=>9,'scheduler_checks'=>12,'browser_checks'=>38,'native_ui_install_checks'=>6,'native_ui_probe_checks'=>5,'javascript_exceptions'=>0,'live_game_cases'=>$games,'bulk_rename_recovery_verified'=>true,'normal_panel_payload_uploads'=>0,'wings_modified'=>false,'neoforge_build'=>$direct['runtime']['build_version'],'comparison'=>['scope'=>'Execution after metadata planning; identical 50ms added to each file/sync request, real Wings/provider/artifact','old_seconds'=>$seconds($baseline),'new_seconds'=>$seconds($measured),'old_requests'=>array_sum($baseline['transfer']['requests']),'new_requests'=>array_sum($measured['transfer']['requests'])],'without_added_latency_seconds'=>$seconds($direct),'native_direct_pull_seconds'=>$direct['transfer']['direct_pull_seconds'],'remaining_limits'=>['Production host was not accessed; these timings are test observations, not bandwidth guarantees','Catalog planning, scheduler waiting and game boot are separate from the execution timing','No live non-SQLite or hundreds-of-servers load test; provider availability and arbitrary releases cannot be guaranteed','Stock Wings cannot exclude every concurrent Files/SFTP/power writer; ambiguous recovery retains the reservation'],'recorded_at'=>gmdate('c')];
file_put_contents($root.'/'.($candidate?'.build/verified-release18.json':'docs/evidence/release.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
if(!$candidate){copy($root.'/.build/package-provenance.json',$root.'/docs/evidence/package-provenance.json');}
echo 'Verified preview.18: ',$sha,'; NeoForge ',$seconds($baseline),' -> ',$seconds($measured),' seconds with simulated latency; ',$seconds($direct)," seconds without it.\n";
