<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify26(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];verify26($version==='0.1.0-preview.26','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
verify26($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;verify26($zip->open($root.'/'.$path)===true,'Cannot read package');
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    verify26(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['plugin.json','LICENSE'],true)){verify26(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native import source mismatch: '.$name);}
}
foreach(['src/Operations/InstallWorkspace.php','src/Operations/LegacyWorkspace.php','src/Operations/NodePreparation.php','src/Console/ReconcileInstall.php','src/Support/ArtifactDownloader.php','src/Support/Integrity.php','src/Support/PluginJava.php','src/Modpacks/ArchiveExtractor.php','src/Modpacks/ArchiveInspector.php','src/Modpacks/ManagedDiff.php','src/Packages/DependencyResolver.php','src/Console/Commands/RepairSchema.php'] as $removed){verify26($zip->locateName('ibyte-minecraft-manager/'.$removed)===false,'Recovery code still packaged: '.$removed);}
verify26($zip->numFiles===55,'Unexpected package files');
$layout=json_decode(file_get_contents($root.'/docs/evidence/release-layout-preview.26.json'),true,512,JSON_THROW_ON_ERROR);
verify26($layout['passed']===2,'Layout checks incomplete');
verify26(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-plugin-preview.26.json'),true,512,JSON_THROW_ON_ERROR)===[],'Secret scan failed');
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.25.zip';if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.25.zip';}
verify26(hash_file('sha256',$baseline)==='de47b0415185d3e824ea023ce8abe29301d81bed0a5cd0a53d5aa6257a67323a','Delivered preview.25 changed');
$old=new ZipArchive;verify26($old->open($baseline)===true,'Cannot read baseline');$changed=[];
foreach($provenance['inputs'] as $input){$name='ibyte-minecraft-manager/'.$input['path'];if($old->getFromName($name)!==$zip->getFromName($name)){$changed[]=$input['path'];}}
$expected=['README.md','plugin.json','resources/views/server.blade.php','resources/views/styles.blade.php','src/Support/SafeHttp.php'];sort($changed);sort($expected);verify26($changed===$expected,'Unexpected implementation changes');
verify26(str_replace('preview.26','preview.25',$zip->getFromName('ibyte-minecraft-manager/src/Support/SafeHttp.php'))===$old->getFromName('ibyte-minecraft-manager/src/Support/SafeHttp.php'),'Provider implementation changed beyond version identifier');
file_put_contents($root.'/docs/evidence/release-preview.26.json',json_encode(['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>55,'changed'=>$changed,'layout'=>$layout,'native_import'=>true,'asset_build_stubbed'=>true,'browser_visual'=>false,'install_engine_rerun'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.26: ZIP/source/native import identity, narrow change scope, two rendered layout checks and plugin secret scan.\n";
