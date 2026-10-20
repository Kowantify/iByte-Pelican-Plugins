<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function releaseRead(string $path):array{global $root;return json_decode(file_get_contents($root.'/'.$path),true,512,JSON_THROW_ON_ERROR);}
function releaseCheck(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
$version=releaseRead('plugin/plugin.json')['version'];
releaseCheck($version==='0.1.0-preview.16','Unexpected release version');
$candidate=in_array('--candidate',$argv,true);$name='ibyte-minecraft-manager-v'.$version.'.zip';
$artifact=($candidate?'.build/':'release/Panel Plugin/').$name;
$provenance=releaseRead('.build/package-provenance.json');$sha=hash_file('sha256',$root.'/'.$artifact);
releaseCheck($sha===$provenance['sha256'] && $provenance['version']===$version,'Artifact/provenance mismatch');
$zip=new ZipArchive;releaseCheck($zip->open($root.'/'.$artifact,ZipArchive::RDONLY)===true,'Cannot open artifact');
releaseCheck($zip->numFiles===count($provenance['inputs']),'ZIP file count mismatch');
$changes=[];$old=releaseRead('docs/evidence/package-provenance-preview.15.json');$oldMap=array_column($old['inputs'],'sha256','path');
foreach($provenance['inputs'] as $entry){
    $path=$entry['path'];$source=$root.'/'.($path==='LICENSE'?'LICENSE':'plugin/'.$path);
    releaseCheck(hash_file('sha256',$source)===$entry['sha256'] && hash('sha256',$zip->getFromName('ibyte-minecraft-manager/'.$path))===$entry['sha256'],'Source/ZIP mismatch: '.$path);
    if($path!=='LICENSE' && $path!=='plugin.json'){
        releaseCheck(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$path)===$entry['sha256'],'Native UI installed different source: '.$path);
    }
    if(($oldMap[$path]??null)!==$entry['sha256']){$changes[]=$path;}
}
$zip->close();
$baseline='release/Archive/ibyte-minecraft-manager-v0.1.0-preview.15.zip';
if(!is_file($root.'/'.$baseline)){$baseline='release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.15.zip';}
releaseCheck(hash_file('sha256',$root.'/'.$baseline)===$old['sha256'] && $old['sha256']==='39056c4ccd05086de7e31b15ca8a625ae960963a965bd56f7290cab491926620','Delivered baseline changed');
foreach(['browser'=>34,'native-ui-install'=>6] as $file=>$count){$data=releaseRead('docs/evidence/'.$file.'.json');releaseCheck(count($data['tests'])===$count&&!array_filter($data['tests'],fn($r)=>$r['status']!=='pass'),'Incomplete UI checks: '.$file);}
releaseCheck(releaseRead('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha,'UI tested a different ZIP');
releaseCheck(releaseRead('docs/evidence/native-ui-install-probe.json')['passed']===5,'UI schema/asset probes failed');
$worker=releaseRead('docs/evidence/native-ui-worker.json');releaseCheck($worker['cached_old_failure']&&!$worker['helper_autoloadable']&&!$worker['process_fake'],'Installer/asset build was mocked');
foreach(['installs'=>53,'catalog-access'=>37,'node-contract'=>15,'remote-downloads'=>9,'scheduler'=>12] as $file=>$count){releaseCheck(releaseRead('docs/evidence/'.$file.'.json')['passed']===$count,'Incomplete checks: '.$file);}
foreach(['unit'=>52,'native'=>29] as $kind=>$count){$log=file_get_contents($root.'/.build/'.$kind.'-16.log');if(str_starts_with($log,"\xff\xfe")){$log=mb_convert_encoding(substr($log,2),'UTF-8','UTF-16LE');}releaseCheck(str_contains($log,'RESULT '.$count.' '),'Missing '.$kind.' test result');}
$performance=releaseRead('docs/evidence/directory-performance.json');releaseCheck($performance['directory_list_requests']===['preview.15'=>1758,'preview.16'=>314]&&!$performance['network_timing_measured'],'Unexpected performance scope/result');
foreach(['plugin','scripts','docs'] as $scope){releaseCheck(releaseRead('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan failed: '.$scope);}
$audit=releaseRead('docs/evidence/composer-audit.json');releaseCheck(!$audit['advisories']&&!$audit['abandoned'],'Dependency audit failed');
releaseCheck(releaseRead('docs/evidence/ui-cleanup-preview.16.json')['owned_helpers_stopped'],'Owned test helpers still running');
$report=['version'=>$version,'artifact'=>$artifact,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$artifact),'packaged_files'=>count($provenance['inputs']),
    'unit_contract_tests'=>52,'native_tests'=>29,'installer_and_action_tests'=>53,'catalog_and_queue_tests'=>37,'raw_node_checks'=>15,'remote_download_checks'=>9,'scheduler_checks'=>12,
    'browser_checks'=>34,'native_ui_install_checks'=>6,'native_ui_post_install_checks'=>5,'native_ui_real_yarn_build'=>true,'main_catalog_install_and_queue_tested'=>true,'detail_live_filters_and_clear_tested'=>true,
    'baseline_release'=>'0.1.0-preview.15','baseline_sha256'=>$old['sha256'],'changed_or_added_files'=>$changes,'directory_performance'=>$performance,
    'live_linux_node_retested_for_this_release'=>false,'historical_live_evidence'=>'docs/evidence/live-runtime-context/matrix.json','no_wings_changes'=>true,'owned_helpers_stopped'=>true,
    'remaining_limits'=>['Changed backend verified with deterministic raw Wings HTTP and installer/recovery fixtures; no new live Linux game startup run','Request-count benchmark excludes payload transfer, promotion and game boot; production timing not measured','Production host and arbitrary releases/gameplay unverified; historical memory and stock Wings concurrency limits remain','Pending install queue is login-session scoped, not cross-device persistent'], 'recorded_at'=>gmdate('c')];
file_put_contents($root.'/'.($candidate?'.build/verified-catalog-release-16.json':'docs/evidence/release.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Verified ',$name,': ',$sha,"; exact native UI ZIP, browser actions, recovery and scoped metadata benchmark.\n";
