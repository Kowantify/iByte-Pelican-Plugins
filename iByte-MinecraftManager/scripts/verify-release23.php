<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify23(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];verify23($version==='0.1.0-preview.23','Wrong version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';$sha=hash_file('sha256',$root.'/'.$path);
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
verify23($provenance['sha256']===$sha,'Provenance mismatch');
$zip=new ZipArchive;verify23($zip->open($root.'/'.$path)===true,'Cannot read package');
foreach($provenance['inputs'] as $entry){$name=$entry['path'];$bytes=file_get_contents($root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name));
    verify23(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['plugin.json','LICENSE'],true)){verify23(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native import source mismatch: '.$name);}
}
foreach(['src/Operations/InstallWorkspace.php','src/Operations/LegacyWorkspace.php','src/Operations/NodePreparation.php','src/Console/ReconcileInstall.php'] as $removed){verify23($zip->locateName('ibyte-minecraft-manager/'.$removed)===false,'Recovery code still packaged: '.$removed);}
verify23($zip->numFiles===63,'Unexpected package files');
$smoke=json_decode(file_get_contents($root.'/docs/evidence/direct-smoke-preview.23.json'),true,512,JSON_THROW_ON_ERROR);
verify23($smoke['passed']===7&&count($smoke['panel_reads'])===1&&str_ends_with($smoke['panel_reads'][0],'/modrinth.index.json'),'Smoke checks incomplete');
verify23(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-plugin-preview.23.json'),true,512,JSON_THROW_ON_ERROR)===[],'Plugin secret scan failed');
$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.22.zip';if(!is_file($baseline)){$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.22.zip';}
verify23(hash_file('sha256',$baseline)==='7e2b20345d42064f4b075dd270ef89278129db5479c285cb46c150e212dc91c9','Delivered preview.22 changed');
file_put_contents($root.'/docs/evidence/release-preview.23.json',json_encode(['version'=>$version,'artifact'=>$path,'sha256'=>$sha,'files'=>63,'smoke'=>$smoke,'native_import'=>true,'asset_build_stubbed'=>true,'full_regression_suite'=>false,'browser_test'=>false,'real_wings'=>false,'game_boot'=>false,'user_requested_small_test'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.23: source/ZIP/native import identity, removed recovery code, seven smoke checks and plugin secret scan. Broader testing deferred.\n";
