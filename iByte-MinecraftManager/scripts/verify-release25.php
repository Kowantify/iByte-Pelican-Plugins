<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify25(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];verify25($version==='0.1.0-preview.25','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
verify25($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;verify25($zip->open($root.'/'.$path)===true,'Cannot read package');
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    verify25(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['plugin.json','LICENSE'],true)){verify25(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native import source mismatch: '.$name);}
}
foreach(['src/Operations/InstallWorkspace.php','src/Operations/LegacyWorkspace.php','src/Operations/NodePreparation.php','src/Console/ReconcileInstall.php','src/Support/ArtifactDownloader.php','src/Support/Integrity.php','src/Support/PluginJava.php','src/Modpacks/ArchiveExtractor.php','src/Modpacks/ArchiveInspector.php','src/Modpacks/ManagedDiff.php','src/Packages/DependencyResolver.php','src/Console/Commands/RepairSchema.php'] as $removed){verify25($zip->locateName('ibyte-minecraft-manager/'.$removed)===false,'Recovery code still packaged: '.$removed);}
verify25($zip->numFiles===55,'Unexpected package files');
$smoke=json_decode(file_get_contents($root.'/docs/evidence/direct-smoke-preview.25.json'),true,512,JSON_THROW_ON_ERROR);
verify25($smoke['passed']===11&&count($smoke['panel_reads'])===1&&str_ends_with($smoke['panel_reads'][0],'/modrinth.index.json'),'Smoke checks incomplete');
verify25(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-plugin-preview.25.json'),true,512,JSON_THROW_ON_ERROR)===[],'Plugin secret scan failed');
$metadata=json_decode(file_get_contents($root.'/docs/evidence/metadata-preview.25.json'),true,512,JSON_THROW_ON_ERROR);
$provider=json_decode(file_get_contents($root.'/docs/evidence/provider-batches-preview.25.json'),true,512,JSON_THROW_ON_ERROR);
verify25($metadata['passed']===10&&$metadata['parallel']['maximum']===4&&$provider['passed']===11,'Focused metadata/provider checks incomplete');
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.24.zip';if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.24.zip';}
verify25(hash_file('sha256',$baseline)==='c01052218d173ef919e28effe03e8ec14c7a0d1e3a600121ecafd8102be9a81f','Delivered preview.24 changed');
$oldZip=new ZipArchive; verify25($oldZip->open($baseline)===true,'Cannot read baseline');
$metrics=[]; foreach(['old'=>$oldZip,'new'=>$zip] as $label=>$archive){$bytes=0;$classes=0;for($i=0;$i<$archive->numFiles;$i++){$name=$archive->getNameIndex($i);if(str_contains($name,'/src/')&&str_ends_with($name,'.php')){$bytes+=strlen($archive->getFromIndex($i));$classes++;}}$metrics[$label]=['src_php_files'=>$classes,'src_bytes'=>$bytes];}
file_put_contents($root.'/docs/evidence/release-preview.25.json',json_encode(['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>55,'source_metrics'=>$metrics,'metadata'=>$metadata,'provider_batches'=>$provider,'smoke'=>$smoke,'native_import'=>true,'asset_build_stubbed'=>true,'full_regression_suite'=>false,'browser_test'=>false,'real_wings'=>false,'game_boot'=>false,'user_requested_small_test'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.25: source/ZIP/native import identity, removed recovery code, eleven smoke checks and plugin secret scan. Broader testing deferred.\n";
