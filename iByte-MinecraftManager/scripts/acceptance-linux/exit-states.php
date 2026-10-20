<?php
declare(strict_types=1);
require_once __DIR__.'/evidence.php';
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$states=[];$passed=true;
foreach($fixture['cases'] as $name=>$case){$process=proc_open(['/usr/bin/docker','--host=unix:///run/imm-docker.sock','inspect','--format','{{json .State}}',$case['uuid']],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($process)){exit(1);}$output=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($process)!==0){exit(1);}$state=json_decode($output,true);$states[$name]=array_intersect_key($state,array_flip(['Status','Running','OOMKilled','Dead','ExitCode','Error','StartedAt','FinishedAt']));$passed=$passed&&!$state['Running']&&!$state['OOMKilled']&&$state['ExitCode']===0;}
$result=['passed'=>$passed,'states'=>$states,'recorded_at'=>gmdate('c')];$target=acceptanceEvidencePath('container-exits.json');file_put_contents($target,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($result,JSON_UNESCAPED_SLASHES),"\n";exit($passed?0:1);
