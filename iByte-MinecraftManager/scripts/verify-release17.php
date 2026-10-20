<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function r17(string $path):array{global $root;return json_decode(file_get_contents($root.'/'.$path),true,512,JSON_THROW_ON_ERROR);}
function require17(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=r17('plugin/plugin.json')['version'];require17($version==='0.1.0-preview.17','Unexpected release');
$candidate=in_array('--candidate',$argv,true);$name='ibyte-minecraft-manager-v'.$version.'.zip';$artifact=($candidate?'.build/':'release/Panel Plugin/').$name;
$provenance=r17('.build/package-provenance.json');$sha=hash_file('sha256',$root.'/'.$artifact);require17($provenance['version']===$version&&$provenance['sha256']===$sha,'Provenance mismatch');
$old=r17('docs/evidence/package-provenance-preview.16.json');$oldMap=array_column($old['inputs'],'sha256','path');
$baseline='release/Archive/ibyte-minecraft-manager-v0.1.0-preview.16.zip';if(!is_file($root.'/'.$baseline)){$baseline='release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.16.zip';}
require17(hash_file('sha256',$root.'/'.$baseline)===$old['sha256']&&$old['sha256']==='f7469cedb2d24a4b7daf785b85e399b94b058a3e8fe116b6835e172a8a302b83','Immutable baseline changed');
$oldZip=new ZipArchive;require17($oldZip->open($root.'/'.$baseline,ZipArchive::RDONLY)===true,'Baseline missing');
$zip=new ZipArchive;require17($zip->open($root.'/'.$artifact,ZipArchive::RDONLY)===true,'Artifact missing');require17($zip->numFiles===count($provenance['inputs']),'ZIP count mismatch');
$ui=['README.md','lang/en/messages.php','src/Filament/Server/MinecraftManager.php','src/Livewire/ProjectInstall.php','resources/views/project-install.blade.php','resources/views/server.blade.php','resources/views/styles.blade.php'];
$versionOnly=['plugin.json','config/ibyte-minecraft-manager.php','src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php'];$changes=[];$unchanged=0;
foreach($provenance['inputs'] as $entry){
    $path=$entry['path'];$source=$root.'/'.($path==='LICENSE'?'LICENSE':'plugin/'.$path);$bytes=file_get_contents($source);
    require17(hash('sha256',$bytes)===$entry['sha256']&&hash('sha256',$zip->getFromName('ibyte-minecraft-manager/'.$path))===$entry['sha256'],'Source/ZIP mismatch: '.$path);
    if(!in_array($path,['LICENSE','plugin.json'],true)){require17(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$path)===$entry['sha256'],'Native UI installed different source: '.$path);}
    require17(isset($oldMap[$path]),'Unexpected new packaged file');
    if($oldMap[$path]===$entry['sha256']){++$unchanged;continue;}$changes[]=$path;
    if(in_array($path,$versionOnly,true)){require17(str_replace($version,'0.1.0-preview.16',$bytes)===$oldZip->getFromName('ibyte-minecraft-manager/'.$path),'Change beyond version: '.$path);}
    else{require17(in_array($path,$ui,true),'Unexpected installer/backend change: '.$path);}
}
$zip->close();$oldZip->close();
foreach(['browser'=>38,'native-ui-install'=>6] as $file=>$count){$report=r17('docs/evidence/'.$file.'.json');require17(count($report['tests'])===$count&&!array_filter($report['tests'],fn($t)=>$t['status']!=='pass'),'Incomplete UI checks: '.$file);}
require17(r17('docs/evidence/browser.json')['javascript_exceptions']===0,'Browser JavaScript error');
require17(r17('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha&&r17('docs/evidence/native-ui-install-probe.json')['passed']===5,'Native UI ZIP/schema/assets mismatch');
$worker=r17('docs/evidence/native-ui-worker.json');require17($worker['cached_old_failure']&&!$worker['helper_autoloadable']&&!$worker['process_fake'],'UI installer/build mocked');
foreach(['catalog-access'=>42,'installs'=>53,'node-contract'=>15,'remote-downloads'=>9,'scheduler'=>12] as $file=>$count){require17(r17('docs/evidence/'.$file.'.json')['passed']===$count,'Missing contract checks: '.$file);}
foreach(['unit'=>52,'native'=>29] as $kind=>$count){$log=file_get_contents($root.'/.build/'.$kind.'-17.log');if(str_starts_with($log,"\xff\xfe")){$log=mb_convert_encoding(substr($log,2),'UTF-8','UTF-16LE');}require17(str_contains($log,'RESULT '.$count.' '),'Missing '.$kind.' result');}
$contexts=0;$projects=0;$artifacts=0;
foreach(['modrinth'=>18,'curseforge'=>15] as $provider=>$count){$live=r17('docs/evidence/live-catalog17-'.$provider.'.json');require17(count($live['cases'])===$count&&!$live['failures']&&$live['credential_cleared'],'Live provider checks failed');$projects+=$count;
    foreach($live['cases'] as $case){require17(count($case['contexts'])===3,'Live contexts missing');foreach($case['contexts'] as $context){require17($context['row_rendered'],'Real row missing');++$contexts;}}
    foreach($live['artifact_checks'] as $item){$file=$root.'/.build/live-catalog17-'.hash('sha256',$item['provider'].$item['release']).'.jar';require17($item['publisher_hash_checked']&&hash_file('sha256',$file)===$item['sha256']&&filesize($file)===$item['size'],'Live artifact differs');++$artifacts;}
}
require17($artifacts===6,'Missing artifact checks');$dialogs=r17('docs/evidence/real-catalog-dialogs17.json');require17(count($dialogs['tests'])===33&&!array_filter($dialogs['tests'],fn($r)=>$r['status']!=='pass'),'Real dialog replay failed');
$prefix='docs/evidence/live-catalog17/';$installed=r17($prefix.'installed-candidate.json');require17($installed['sha256']===$sha&&$installed['matched_files']===65,'Live node ran another candidate');
$matrix=r17($prefix.'matrix.json');$runtimeCases=[];$normalPulls=0;
foreach(['neoforge263'=>['26.3',777],'fabric1211'=>['1.21.1',767],'forge1201'=>['1.20.1',763]] as $name=>[$minecraft,$protocol]){
    $row=$matrix['cases'][$name];$install=$row['installation'];$mods=$row['packages'];$boot=$row['packages-startup'];
    require17($install['status']==='succeeded'&&$install['runtime']['version']===$minecraft&&$install['runtime_variables_match']&&$install['custom_variables_preserved']&&$install['startup_preserved'],'Runtime failed: '.$name);
    require17($mods['status']==='succeeded'&&count($mods['projects'])===2&&$mods['world_unchanged']&&$mods['readback_verified'],'Mods failed: '.$name);
    require17($boot['ready']&&$boot['stopped']&&$boot['fresh_done_log']&&$boot['expected_protocol_verified']&&$boot['game_status']['version']['protocol']===$protocol&&$boot['runtime_variables_in_container_verified'],'Game startup failed: '.$name);
    foreach([$install,$mods] as $operation){require17($operation['transfer']['panel_uploads']===0&&$operation['transfer']['wings_pulls']>0,'Provider payload was not a direct node pull');$normalPulls+=$operation['transfer']['wings_pulls'];}
    $runtimeCases[]=['case'=>$name,'minecraft'=>$minecraft,'files'=>$install['files'],'runtime_install_seconds'=>strtotime($install['finished_at'])-strtotime($install['started_at']),'mods'=>array_column($mods['projects'],'title'),'game_started_and_stopped'=>true];
}
$queue=r17($prefix.'live-ui-mod-queue.json');require17($queue['passed']&&$queue['queued_before_worker']&&$queue['transfer']['panel_uploads']===0,'Actual UI/worker queue failed');$normalPulls+=$queue['transfer']['wings_pulls'];
$queuedBoot=r17($prefix.'neoforge263-queued-mods-startup.json');require17($queuedBoot['ready']&&$queuedBoot['stopped'],'Queued game startup failed');
$recovery=r17($prefix.'live-rollback.json');require17($recovery['passed']&&$recovery['fault_injected']&&$recovery['original_mod_hashes_preserved']&&$recovery['world_unchanged']&&$recovery['reservation_released'],'Actual recovery failed');
$state=r17($prefix.'final-state.json');require17($state['passed']&&count($state['containers'])===3&&$state['active_reservations']===0&&$state['pending_jobs']===0&&$state['credential_absent']&&$state['wings_sha256']==='19dda386e02041da1f1fbcc73f34e4bb01c1fbc2d499aac79a6be3a51ba7f0e8','Final node state failed');
require17(r17($prefix.'cleanup.json')['owned_distro_removed']&&r17('docs/evidence/ui-cleanup-preview.17.json')['owned_helpers_stopped'],'Owned helper cleanup incomplete');
foreach(['plugin','scripts','docs'] as $scope){require17(r17('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan failed');}$audit=r17('docs/evidence/composer-audit.json');require17(!$audit['advisories']&&!$audit['abandoned'],'Dependency audit failed');
$report=['version'=>$version,'artifact'=>$artifact,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$artifact),'packaged_files'=>count($provenance['inputs']),'unit_contract_tests'=>52,'native_tests'=>29,'installer_and_action_tests'=>53,'catalog_tests'=>42,'raw_node_checks'=>15,'remote_download_checks'=>9,'scheduler_checks'=>12,'browser_checks'=>38,'native_ui_install_checks'=>6,'native_ui_post_install_checks'=>5,'browser_javascript_exceptions'=>0,'live_provider_project_cases'=>$projects,'live_version_loader_contexts'=>$contexts,'real_metadata_dialog_checks'=>33,'publisher_verified_artifacts'=>$artifacts,'live_runtime_cases'=>$runtimeCases,'normal_provider_wings_pulls'=>$normalPulls,'normal_provider_panel_uploads'=>0,'actual_ui_queue_worker_delivery'=>true,'actual_failure_rollback_verified'=>true,'no_wings_changes'=>true,'unchanged_packaged_files'=>$unchanged,'changed_files'=>$changes,'baseline_version'=>'0.1.0-preview.16','baseline_sha256'=>$old['sha256'],'owned_helpers_removed'=>true,'php_linux'=>$state['php'],'debian'=>$state['debian'],'remaining_limits'=>['Production host was not accessed or deployed; provider availability and arbitrary releases/gameplay cannot be guaranteed','Fresh live game tests cover these three runtimes/mod batches; previous broader pack/software runs remain historical','SQLite executed; other database engines were not live-tested in this release','Stock Wings cannot exclude every native Files/SFTP/power writer; concurrent operator changes can require reconciliation','Pending install queue is scoped to the login session, actor and server'],'recorded_at'=>gmdate('c')];
file_put_contents($root.'/'.($candidate?'.build/verified-release17.json':'docs/evidence/release.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Verified ',basename($artifact),': ',$sha,"; native UI, real provider data, actual Wings games/queue/recovery and cleanup.\n";
