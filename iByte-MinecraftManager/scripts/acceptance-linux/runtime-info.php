<?php
declare(strict_types=1);
require_once __DIR__.'/evidence.php';
function output(array $command):string{$process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($process)){throw new RuntimeException('Cannot read runtime identity');}$data=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($process)!==0){throw new RuntimeException('Runtime identity failed');}return trim($data);}
$docker=['/usr/bin/docker','--host=unix:///run/imm-docker.sock'];$result=['os'=>output(['cat','/etc/debian_version']),'php'=>PHP_VERSION,'wings'=>output(['/opt/imm/bin/wings','version']),'wings_sha256'=>hash_file('sha256','/opt/imm/bin/wings'),'docker'=>output([...$docker,'version','--format','{{.Server.Version}}']),'images'=>[]];
foreach([17,21,25] as $java){$result['images']['java_'.$java]=json_decode(output([...$docker,'image','inspect','ghcr.io/pelican-eggs/yolks:java_'.$java,'--format','{{json .RepoDigests}}']),true);}
$target=acceptanceEvidencePath('runtime.json');file_put_contents($target,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo "Official binary and Docker image provenance recorded.\n";
