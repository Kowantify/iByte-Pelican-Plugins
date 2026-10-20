<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function read19(string $name):array{global $root;return json_decode(file_get_contents($root.'/'.$name),true,512,JSON_THROW_ON_ERROR);}
function require19(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=read19('plugin/plugin.json')['version'];require19($version==='0.1.0-preview.19','Wrong version');$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';
$sha=hash_file('sha256',$root.'/'.$path);$provenance=read19('.build/package-provenance.json');require19($sha===$provenance['sha256'],'Provenance mismatch');
$zip=new ZipArchive;require19($zip->open($root.'/'.$path)===true,'Release missing');$old=new ZipArchive;require19($old->open($root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.18.zip')===true,'Baseline missing');
$allowed=['README.md','plugin.json','config/ibyte-minecraft-manager.php','src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php','src/Operations/NodeFiles.php','src/Operations/InstallExecutor.php'];$changes=[];
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));require19(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch');if($old->getFromName('ibyte-minecraft-manager/'.$name)!==$bytes){require19(in_array($name,$allowed,true),'Unexpected package change: '.$name);$changes[]=$name;}}
require19($zip->numFiles===65,'Wrong content count');$zip->close();$old->close();
foreach(['unit'=>52,'native'=>29,'install'=>55,'catalog'=>42,'node'=>15,'remote'=>9,'scheduler'=>12,'verification'=>16] as $name=>$count){require19(str_contains(file_get_contents($root.'/docs/evidence/'.$name.'-preview.19.log'),'RESULT '.$count),'Regression result missing: '.$name);}
$benchmark=read19('docs/evidence/verification-preview.19.json');require19($benchmark['passed']===16&&$benchmark['concurrent_stats']['maximum']===4&&$benchmark['concurrent_seconds']<$benchmark['baseline_seconds']*0.55,'Async verification evidence failed');
foreach(['plugin','scripts','docs'] as $scope){require19(read19('docs/evidence/gitleaks-'.$scope.'.json')===[],'Credential scan failed');}
require19(hash_file('sha256',$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.18.zip')==='8943065c77a1ff5018d77351895ab90df3fc530d07eeb2d78f6a828d38b5d958','Delivered baseline changed');
$report=['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$path),'packaged_files'=>65,'changed_files'=>$changes,'verification'=>$benchmark,'fresh_game_boot'=>false,'fresh_full_browser_or_ui_build'=>false,'scope'=>'Focused final-verification patch; native lifecycle and install/recovery regression tests plus real delayed HTTP verification. Previous live game/UI evidence remains historical.','wings_modified'=>false,'recorded_at'=>gmdate('c')];
file_put_contents($root.'/docs/evidence/release.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");copy($root.'/.build/package-provenance.json',$root.'/docs/evidence/package-provenance.json');echo 'Verified preview.19 ZIP: ',$sha,"\n";
