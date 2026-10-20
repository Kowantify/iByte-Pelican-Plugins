<?php
declare(strict_types=1);
$root='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager';
foreach(['cobblemon','nexus','odyssey'] as $name){$file='/opt/imm/'.$name.'-startup.json';$data=json_decode(file_get_contents($file),true);if(empty($data['ready'])||empty($data['stopped'])||empty($data['expected_protocol_verified'])||empty($data['fresh_done_log'])){fwrite(STDERR,'Strict readiness/shutdown incomplete: '.$name."\n");exit(1);}}
foreach(['cobblemon','nexus','odyssey'] as $name){$target='/opt/imm/'.$name.'-queued-pack-startup.json';$prior=json_decode(file_get_contents($target),true);if($name==='odyssey'&&empty($prior['game_status']['version'])){$prior['note']='Initial recorded reply lacked version/players; superseded by fresh-log and expected-protocol recheck. Not counted as final readiness evidence.';file_put_contents($root.'/docs/evidence/live-acceptance/odyssey-incomplete-status-probe.json',json_encode($prior,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
    $data=json_decode(file_get_contents('/opt/imm/'.$name.'-startup.json'),true);$data['post_install_recheck']=true;$data['operation']=json_decode(file_get_contents('/opt/imm/'.$name.'-queued-pack.json'),true)['operation'];file_put_contents($target,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
echo "All three exact-ZIP pack startups pass fresh-log, expected-version/protocol and clean-shutdown checks.\n";
