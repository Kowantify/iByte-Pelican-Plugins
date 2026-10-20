<?php
declare(strict_types=1);

// This release changes the Panel flow. Do not relabel historical node runs.
$root=dirname(__DIR__);
function uiRead(string $path):array{global $root;return json_decode(file_get_contents($root.'/'.$path),true,512,JSON_THROW_ON_ERROR);}
function uiRequire(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
$version=uiRead('plugin/plugin.json')['version'];
uiRequire($version==='0.1.0-preview.15','Unexpected UI release version');
$candidate=in_array('--candidate',$argv,true);$preCleanup=in_array('--pre-cleanup',$argv,true);
uiRequire(!$preCleanup||$candidate,'Pre-cleanup is candidate-only');
$name='ibyte-minecraft-manager-v'.$version.'.zip';$artifact=($candidate?'.build/':'release/Panel Plugin/').$name;
$provenance=uiRead('.build/package-provenance.json');$sha=hash_file('sha256',$root.'/'.$artifact);
uiRequire($sha===$provenance['sha256']&&$provenance['version']===$version,'Artifact differs from source provenance');
foreach($provenance['inputs'] as $entry){$file=$entry['path']==='LICENSE'?'LICENSE':'plugin/'.$entry['path'];uiRequire(hash_file('sha256',$root.'/'.$file)===$entry['sha256'],'Packaged source changed: '.$file);}
$baseline=uiRead('docs/evidence/release-preview.14.json');$oldInputs=uiRead('docs/evidence/package-provenance-preview.14.json');
$baselinePath='release/Archive/ibyte-minecraft-manager-v0.1.0-preview.14.zip';
if(!is_file($root.'/'.$baselinePath)){$baselinePath='release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.14.zip';}
uiRequire(hash_file('sha256',$root.'/'.$baselinePath)===$baseline['sha256']&&$baseline['sha256']===$oldInputs['sha256'],'Baseline release was modified');
$zip=new ZipArchive;uiRequire($zip->open($root.'/'.$baselinePath,ZipArchive::RDONLY)===true,'Cannot open baseline');
$uiFiles=['README.md','plugin.json','config/ibyte-minecraft-manager.php','lang/en/messages.php','src/Filament/Server/MinecraftManager.php','resources/views/server.blade.php','resources/views/styles.blade.php','resources/views/admin.blade.php'];
$versionOnly=['src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php'];
$oldMap=array_column($oldInputs['inputs'],'sha256','path');$matched=0;$versionMatched=0;
foreach($provenance['inputs'] as $entry){
    $path=$entry['path'];uiRequire(isset($oldMap[$path]),'Unexpected new packaged backend file: '.$path);
    if(in_array($path,['plugin.json','config/ibyte-minecraft-manager.php'],true)){
        uiRequire(str_replace($version,$baseline['version'],file_get_contents($root.'/plugin/'.$path))===$zip->getFromName('ibyte-minecraft-manager/'.$path),'Manifest/config changed beyond version: '.$path);continue;
    }
    if(in_array($path,$uiFiles,true)){continue;}
    if(in_array($path,$versionOnly,true)){
        $old=$zip->getFromName('ibyte-minecraft-manager/'.$path);$current=file_get_contents($root.'/plugin/'.$path);
        uiRequire(str_replace($version,$baseline['version'],$current)===$old,'Transfer code changed beyond user-agent version: '.$path);++$versionMatched;
    }else{uiRequire($oldMap[$path]===$entry['sha256'],'Backend changed: '.$path);++$matched;}
}
$zip->close();
$removed=array_values(array_diff(array_keys($oldMap),array_column($provenance['inputs'],'path')));
uiRequire($removed===['resources/views/review.blade.php'],'Unexpected removed backend file');
foreach(['native-ui-install'=>6,'browser'=>32] as $file=>$count){$report=uiRead('docs/evidence/'.$file.'.json');uiRequire(count($report['tests'])===$count&&!array_filter($report['tests'],fn($row)=>$row['status']!=='pass'),'Failed UI checks: '.$file);}
uiRequire(uiRead('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha,'UI installed another ZIP');
uiRequire(uiRead('docs/evidence/native-ui-install-probe.json')['passed']===5,'Post-install probes failed');
$worker=uiRead('docs/evidence/native-ui-worker.json');uiRequire($worker['cached_old_failure']&&!$worker['helper_autoloadable']&&!$worker['process_fake'],'Native worker/build was mocked');
uiRequire(uiRead('docs/evidence/installs.json')['passed']===53&&uiRead('docs/evidence/catalog-access.json')['passed']===32,'Action or executor checks incomplete');
foreach(['unit'=>52,'native'=>29] as $kind=>$count){$log=file_get_contents($root.'/.build/'.$kind.'-15.log');if(str_starts_with($log,"\xff\xfe")){$log=mb_convert_encoding(substr($log,2),'UTF-8','UTF-16LE');}uiRequire(str_contains($log,'RESULT '.$count.' '),'Missing passing '.$kind.' result');}
$audit=uiRead('docs/evidence/composer-audit.json');uiRequire(!$audit['advisories']&&!$audit['abandoned'],'Dependency audit is not clean');
foreach(['plugin','scripts','docs'] as $scope){uiRequire(uiRead('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan not clean: '.$scope);}
if(!$preCleanup){uiRequire(uiRead('docs/evidence/ui-cleanup-preview.15.json')['owned_helpers_stopped'],'Owned UI helpers still running');}
$template=file_get_contents($root.'/plugin/resources/views/server.blade.php');
uiRequire(!str_contains($template,'wire:confirm')&&!str_contains($template,'imm-review')&&!str_contains($template,'Review exact'),'Review flow remains');
$report=['version'=>$version,'artifact'=>$artifact,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$artifact),'packaged_files'=>count($provenance['inputs']),
    'unit_contract_tests'=>52,'native_tests'=>29,'installer_and_action_tests'=>53,'catalog_and_queue_tests'=>32,'browser_checks'=>32,'native_ui_install_checks'=>6,'native_ui_post_install_checks'=>5,
    'direct_install_without_review'=>true,'pending_queue_survives_session_reload'=>true,'queue_actor_and_tenant_isolation_tested'=>true,'single_and_batch_actions_execute_installer_with_node_fixture'=>true,'running_server_refused_before_mutation'=>true,
    'unchanged_backend_files'=>$matched,'transfer_files_changed_only_in_user_agent'=>$versionMatched,'baseline_release'=>'0.1.0-preview.14','baseline_sha256'=>$baseline['sha256'],'baseline_live_evidence'=>'docs/evidence/live-runtime-context/matrix.json',
    'live_node_retested_for_this_ui_release'=>false,'native_ui_real_yarn_build'=>true,'no_wings_changes'=>true,'owned_helpers_stopped'=>!$preCleanup,
    'remaining_limits'=>['New UI/actions tested with deterministic providers/node fixture; real node/game runs belong to unchanged-backend preview.14','Pending queue is scoped to the login session, actor and server; not cross-device persistent','Production host, arbitrary releases and exhaustive gameplay untested; baseline memory and Wings concurrency limits still apply'],
    'recorded_at'=>gmdate('c')];
$output=$candidate?'.build/verified-ui-release-15'.($preCleanup?'-pre-cleanup':'').'.json':'docs/evidence/release.json';
file_put_contents($root.'/'.$output,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Verified UI release ',$name,': ',$sha,'; native actions, browser/install and unchanged backend identity.',"\n";
