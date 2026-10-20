<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function read20(string $path):array{global $root;return json_decode(ltrim(file_get_contents($root.'/'.$path),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);}
function require20(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=read20('plugin/plugin.json')['version'];require20($version==='0.1.0-preview.20','Wrong version');
$candidate=in_array('--candidate',$argv,true);$path=($candidate?'.build/':'release/Panel Plugin/').'ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=read20('.build/package-provenance.json');require20($sha===$provenance['sha256']&&$provenance['version']===$version,'Provenance mismatch');
$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.19.zip';if(!is_file($baseline)){$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.19.zip';}
require20(hash_file('sha256',$baseline)==='21becfb2efa4c466123aabd9b6fbcf5fc72303b29897f1b7089e753715b8c343','Delivered preview.19 changed');
$zip=new ZipArchive;require20($zip->open($root.'/'.$path)===true,'ZIP missing');$old=new ZipArchive;require20($old->open($baseline)===true,'Baseline missing');
$allowed=['README.md','plugin.json','config/ibyte-minecraft-manager.php','src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php','src/Support/Compatibility.php','src/Catalog/Modrinth.php','src/Catalog/CurseForge.php','src/Operations/InstallExecutor.php','src/Operations/CheckReview.php','src/Operations/ReviewService.php','src/Operations/Status.php','src/Livewire/ActivityFeed.php','resources/views/install-progress.blade.php','resources/views/server.blade.php','src/Filament/Server/MinecraftManager.php','lang/en/messages.php'];$changed=[];
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$source=$root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name);$bytes=file_get_contents($source);
    require20(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['LICENSE','plugin.json'],true)){require20(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native UI installed other source: '.$name);}
    if($old->getFromName('ibyte-minecraft-manager/'.$name)!==$bytes){require20(in_array($name,$allowed,true),'Unexpected package change: '.$name);$changed[]=$name;}
}
require20($zip->numFiles===65&&count($provenance['inputs'])===65,'Unexpected ZIP contents');$zip->close();$old->close();
foreach(['unit'=>52,'native'=>29,'install'=>62,'catalog'=>45,'node'=>15,'remote'=>9,'scheduler'=>12] as $kind=>$count){require20(str_contains(file_get_contents($root.'/docs/evidence/'.$kind.'-preview.20.log'),'RESULT '.$count),'Missing regression checks: '.$kind);}
foreach(['browser'=>40,'native-ui-install'=>6] as $kind=>$count){$data=read20('docs/evidence/'.$kind.'.json');require20(count($data['tests'])===$count&&!array_filter($data['tests'],fn($row)=>$row['status']!=='pass'),'Incomplete UI checks: '.$kind);}
require20(read20('docs/evidence/browser.json')['javascript_exceptions']===0,'Browser JavaScript exceptions');
require20(read20('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha&&read20('docs/evidence/native-ui-install-probe.json')['passed']===5,'UI artifact/schema/build mismatch');
$worker=read20('docs/evidence/native-ui-worker.json');require20($worker['cached_old_failure']&&!$worker['helper_autoloadable']&&!$worker['process_fake'],'UI installer was mocked');
$live=read20('docs/evidence/live-release-filters-preview.20.json');require20($live['verified']&&$live['provider']==='modrinth'&&count($live['results'])===3,'Missing live release filters');
foreach($live['results'] as $case){require20($case['matching_count']>0,'Empty live filter case');foreach($case['examples'] as $release){require20(in_array($case['version'],$release['game_versions'],true)&&in_array($case['loader'],$release['loaders'],true),'Live provider mismatch');}}
foreach(['plugin','scripts','docs'] as $scope){require20(read20('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan failed');}
require20(read20('docs/evidence/ui-cleanup-preview.20.json')['owned_helpers_stopped'],'Owned UI helpers remain');
$report=['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$path),'packaged_files'=>65,'changed_files'=>$changed,'unit_checks'=>52,'native_checks'=>29,'installer_checks'=>62,'catalog_checks'=>45,'raw_node_checks'=>15,'remote_download_checks'=>9,'scheduler_checks'=>12,'browser_checks'=>40,'javascript_exceptions'=>0,'native_ui_install_checks'=>6,'native_ui_probe_checks'=>5,'live_provider_filter_cases'=>3,'live_provider_returned_options'=>array_sum(array_column($live['results'],'matching_count')),'fresh_game_boot'=>false,'wings_modified'=>false,'concurrent_verifier_unchanged_from_preview19'=>true,'scope'=>'Actual native UI install/build, browser history/recovery/Search and regression fixtures; live Modrinth filter metadata. No fresh game boot or production access. Original Nexus failure cause remains unknown.','recorded_at'=>gmdate('c')];
file_put_contents($root.'/'.($candidate?'.build/verified-release20.json':'docs/evidence/release.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
if(!$candidate){copy($root.'/.build/package-provenance.json',$root.'/docs/evidence/package-provenance.json');}
echo 'Verified preview.20: ',$sha,"; UI build, 40 browser checks, cancellation/recovery and release filters.\n";
