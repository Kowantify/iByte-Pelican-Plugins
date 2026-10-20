<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify24(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];verify24($version==='0.1.0-preview.24','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
verify24($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;verify24($zip->open($root.'/'.$path)===true,'Cannot read package');
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    verify24(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['plugin.json','LICENSE'],true)){verify24(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native import source mismatch: '.$name);}
}
foreach(['src/Operations/InstallWorkspace.php','src/Operations/LegacyWorkspace.php','src/Operations/NodePreparation.php','src/Console/ReconcileInstall.php','src/Support/ArtifactDownloader.php','src/Support/Integrity.php','src/Support/PluginJava.php','src/Modpacks/ArchiveExtractor.php','src/Modpacks/ArchiveInspector.php','src/Modpacks/ManagedDiff.php','src/Packages/DependencyResolver.php','src/Console/Commands/RepairSchema.php'] as $removed){verify24($zip->locateName('ibyte-minecraft-manager/'.$removed)===false,'Recovery code still packaged: '.$removed);}
verify24($zip->numFiles===55,'Unexpected package files');
$smoke=json_decode(file_get_contents($root.'/docs/evidence/direct-smoke-preview.24.json'),true,512,JSON_THROW_ON_ERROR);
verify24($smoke['passed']===9&&count($smoke['panel_reads'])===1&&str_ends_with($smoke['panel_reads'][0],'/modrinth.index.json'),'Smoke checks incomplete');
verify24(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-plugin-preview.24.json'),true,512,JSON_THROW_ON_ERROR)===[],'Plugin secret scan failed');
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.23.zip';if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.23.zip';}
verify24(hash_file('sha256',$baseline)==='b83ed5c4741720dc0748d44805c8f270a8c243809303ee4a5adb799d69b3e149','Delivered preview.23 changed');
$oldZip=new ZipArchive; verify24($oldZip->open($baseline)===true,'Cannot read baseline');
$metrics=[]; foreach(['old'=>$oldZip,'new'=>$zip] as $label=>$archive){$bytes=0;$classes=0;for($i=0;$i<$archive->numFiles;$i++){$name=$archive->getNameIndex($i);if(str_contains($name,'/src/')&&str_ends_with($name,'.php')){$bytes+=strlen($archive->getFromIndex($i));$classes++;}}$metrics[$label]=['src_php_files'=>$classes,'src_bytes'=>$bytes];}
file_put_contents($root.'/docs/evidence/release-preview.24.json',json_encode(['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>55,'cleanup_metrics'=>$metrics,'smoke'=>$smoke,'native_import'=>true,'asset_build_stubbed'=>true,'full_regression_suite'=>false,'browser_test'=>false,'real_wings'=>false,'game_boot'=>false,'user_requested_small_test'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.24: source/ZIP/native import identity, removed recovery code, nine smoke checks and plugin secret scan. Broader testing deferred.\n";
