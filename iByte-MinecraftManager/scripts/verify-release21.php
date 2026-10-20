<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function require21(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$version=json_decode(file_get_contents($root.'/plugin/plugin.json'),true,512,JSON_THROW_ON_ERROR)['version'];
require21($version==='0.1.0-preview.21','Wrong release version');
$path='release/Panel Plugin/ibyte-minecraft-manager-v'.$version.'.zip';
$baseline=$root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.20.zip';
if(!is_file($baseline)){$baseline=$root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.20.zip';}
require21(hash_file('sha256',$baseline)==='760c7c7154e0a41c7113ee61ca02660cc81b3af1a85a26cdb6a17d1e18fdc1ec','Delivered preview.20 changed');
$provenance=json_decode(file_get_contents($root.'/.build/package-provenance.json'),true,512,JSON_THROW_ON_ERROR);
require21(hash_file('sha256',$root.'/'.$path)===$provenance['sha256'],'Provenance mismatch');
$zip=new ZipArchive;$old=new ZipArchive;
require21($zip->open($root.'/'.$path)===true&&$old->open($baseline)===true,'ZIP unreadable');
$allowed=['plugin.json','config/ibyte-minecraft-manager.php','README.md','src/Support/SafeHttp.php','src/Support/ArtifactDownloader.php','resources/views/server.blade.php'];$changed=[];
foreach($provenance['inputs'] as $entry){
    $name=$entry['path'];$source=$root.'/'.($name==='LICENSE'?'LICENSE':'plugin/'.$name);$bytes=file_get_contents($source);
    require21(hash('sha256',$bytes)===$entry['sha256']&&$zip->getFromName('ibyte-minecraft-manager/'.$name)===$bytes,'Source/ZIP mismatch: '.$name);
    if(!in_array($name,['LICENSE','plugin.json'],true)){require21(hash_file('sha256',$root.'/.research/panel/plugins/ibyte-minecraft-manager/'.$name)===$entry['sha256'],'Native installed source mismatch: '.$name);}
    if($old->getFromName('ibyte-minecraft-manager/'.$name)!==$bytes){require21(in_array($name,$allowed,true),'Unexpected change: '.$name);$changed[]=$name;}
}
require21($zip->numFiles===65&&count($provenance['inputs'])===65,'Unexpected package size');
foreach(['native'=>29,'catalog'=>46] as $kind=>$count){require21(str_contains(file_get_contents($root.'/docs/evidence/'.$kind.'-preview.21.log'),'RESULT '.$count),'Missing test result: '.$kind);}
foreach(['plugin','scripts','docs'] as $scope){require21(json_decode(file_get_contents($root.'/docs/evidence/gitleaks-'.$scope.'-preview.21.json'),true,512,JSON_THROW_ON_ERROR)===[],'Secret scan failed: '.$scope);}
$report=['version'=>$version,'artifact'=>$path,'sha256'=>$provenance['sha256'],'files'=>65,'changed'=>$changed,'native_lifecycle'=>29,'catalog_rendered_livewire'=>46,'fresh_browser'=>false,'fresh_game_boot'=>false,'production_access'=>false,'installer_unchanged'=>true];
file_put_contents($root.'/docs/evidence/release-preview.21.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS preview.21: exact source/ZIP/native installation identity, focused change scope, checks and scans.\n";
