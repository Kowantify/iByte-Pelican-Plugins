<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function require22(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function read22(string $path):array{global $root;return json_decode(ltrim(file_get_contents($root.'/'.$path),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);}
$version=read22('plugin/plugin.json')['version'];require22($version==='0.1.0-preview.22','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=read22('.build/package-provenance.json');require22($sha===$provenance['sha256'],'Provenance mismatch');
$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.21.zip';if(!is_file($baseline)){$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.21.zip';}
require22(hash_file('sha256',$baseline)==='87207ca78648c9069888197d5a3307c300598c1cd8315f10872a9be40ae02173','Delivered preview.21 changed');
$zip=new ZipArchive;$old=new ZipArchive;require22($zip->open($root.'/'.$path)===true&&$old->open($baseline)===true,'Missing ZIP');
$allowed=['plugin.json','README.md','config/ibyte-minecraft-manager.php','src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php','src/Operations/NodeFiles.php','src/Operations/NodePreparation.php','src/Operations/InstallWorkspace.php','src/Operations/InstallExecutor.php','src/Operations/ProgressClock.php','resources/views/install-progress.blade.php'];$changed=[];
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    require22(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['LICENSE','plugin.json'],true)){require22(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native UI installed different source: '.$name);}
    if($old->getFromName('ibyte-minecraft-manager/'.$name)!==$bytes){require22(in_array($name,$allowed,true),'Unexpected change: '.$name);$changed[]=$name;}
}
require22($zip->numFiles===66&&count($provenance['inputs'])===66,'Unexpected file count');
foreach(['unit'=>52,'native'=>29,'install'=>65,'catalog'=>46,'scheduler'=>12,'node'=>17,'initial'=>19,'verification'=>16] as $kind=>$count){require22(str_contains(file_get_contents($root.'/docs/evidence/'.$kind.'-preview.22.log'),'RESULT '.$count),'Missing checks: '.$kind);}
$initial=read22('docs/evidence/initial-verification-preview.22.json');require22($initial['passed']===19&&$initial['parallel']['maximum']===4&&$initial['scale']['maximum']===4&&$initial['scale_files']===1000&&$initial['speedup']>2,'Initial verification benchmark failed');
foreach(['browser'=>41,'native-ui-install'=>6] as $kind=>$count){$report=read22('docs/evidence/'.$kind.'.json');require22(count($report['tests'])===$count&&!array_filter($report['tests'],static fn($row)=>$row['status']!=='pass'),'UI checks failed: '.$kind);}
require22(read22('docs/evidence/browser.json')['javascript_exceptions']===0,'Browser JavaScript exceptions');
require22(read22('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha&&read22('docs/evidence/native-ui-install-probe.json')['passed']===5,'UI artifact/build mismatch');
foreach(['plugin','scripts','docs'] as $scope){require22(read22('docs/evidence/gitleaks-'.$scope.'-preview.22.json')===[],'Secret scan failed: '.$scope);}
require22(read22('docs/evidence/ui-cleanup-preview.22.json')['owned_helpers_stopped']===true,'Owned helpers remain running');
$report=['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>66,'changed'=>$changed,'initial_verification'=>$initial,'installer_checks'=>65,'browser_checks'=>41,'native_ui_install'=>6,'fresh_linux_wings'=>false,'fresh_game_boot'=>false,'production_access'=>false];
file_put_contents($root.'/docs/evidence/release-preview.22.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.22: exact source/ZIP/native UI identity, bounded concurrency, regressions, scans and helper cleanup.\n";
