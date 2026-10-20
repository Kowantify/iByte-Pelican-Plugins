<?php
declare(strict_types=1);
function acceptanceEvidencePath(string $name): string
{
    $folder=getenv('IMM_ACCEPTANCE_EVIDENCE')?:'live-runtime-context';
    if(!in_array($folder,['live-acceptance','live-direct-downloads','live-direct-destinations','live-runtime-context','live-catalog17','live-performance18','live-verification19'],true)||!preg_match('/\A[a-z0-9-]+\.json\z/D',$name)){throw new RuntimeException('Invalid evidence path');}
    $root='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager/docs/evidence/'.$folder;
    if(!is_dir($root)){mkdir($root,0700,true);}
    return $root.'/'.$name;
}
