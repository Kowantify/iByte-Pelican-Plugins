<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify27(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];verify27($version==='0.1.0-preview.27','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
verify27($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;verify27($zip->open($root.'/'.$path)===true,'Cannot read package');
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    verify27(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['plugin.json','LICENSE'],true)){verify27(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native import source mismatch: '.$name);}
}
foreach(['src/Operations/InstallWorkspace.php','src/Operations/LegacyWorkspace.php','src/Operations/NodePreparation.php','src/Console/ReconcileInstall.php','src/Support/ArtifactDownloader.php','src/Support/Integrity.php','src/Support/PluginJava.php','src/Modpacks/ArchiveExtractor.php','src/Modpacks/ArchiveInspector.php','src/Modpacks/ManagedDiff.php','src/Packages/DependencyResolver.php','src/Console/Commands/RepairSchema.php'] as $removed){verify27($zip->locateName('ibyte-minecraft-manager/'.$removed)===false,'Recovery code still packaged: '.$removed);}
verify27($zip->numFiles===55,'Unexpected package files');
$layout=json_decode(file_get_contents($root.'/docs/evidence/software-labels-preview.27.json'),true,512,JSON_THROW_ON_ERROR);
verify27($layout['passed']===3,'Layout checks incomplete');
verify27(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-plugin-preview.27.json'),true,512,JSON_THROW_ON_ERROR)===[],'Secret scan failed');
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.26.zip';if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.26.zip';}
verify27(hash_file('sha256',$baseline)==='94b86d4e55c75e4c9108b039b0174403973962a5cd6b1869b694aa52ea3a3273','Delivered preview.26 changed');
$old=new ZipArchive;verify27($old->open($baseline)===true,'Cannot read baseline');$changed=[];
foreach($provenance['inputs'] as $input){$name='ibyte-minecraft-manager/'.$input['path'];if($old->getFromName($name)!==$zip->getFromName($name)){$changed[]=$input['path'];}}
$expected=['README.md','plugin.json','src/Catalog/McJars.php','src/Support/SafeHttp.php'];sort($changed);sort($expected);verify27($changed===$expected,'Unexpected implementation changes');
verify27(str_replace('preview.27','preview.26',$zip->getFromName('ibyte-minecraft-manager/src/Support/SafeHttp.php'))===$old->getFromName('ibyte-minecraft-manager/src/Support/SafeHttp.php'),'Provider implementation changed beyond version identifier');
file_put_contents($root.'/docs/evidence/release-preview.27.json',json_encode(['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>55,'changed'=>$changed,'layout'=>$layout,'native_import'=>true,'asset_build_stubbed'=>true,'browser_visual'=>false,'install_engine_rerun'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.27: ZIP/source/native import identity, narrow change scope, three software label checks and plugin secret scan.\n";
