<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function request(string $url):string{$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'iByte-acceptance-test',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>true]);$body=curl_exec($c);if($body===false||curl_getinfo($c,CURLINFO_RESPONSE_CODE)!==200){throw new RuntimeException('Official source download failed');}return $body;}
$release=json_decode(request('https://api.github.com/repos/pelican/panel/releases/tags/v1.0.0-rc.1'),true,64,JSON_THROW_ON_ERROR);
$asset=null;foreach($release['assets'] as $entry){if($entry['name']==='panel.tar.gz'){$asset=$entry;break;}}
if(!$asset){throw new RuntimeException('Official RC.1 archive missing');}$bytes=request($asset['browser_download_url']);$hash=hash('sha256',$bytes);
if(!empty($asset['digest'])&&!hash_equals('sha256:'.$hash,$asset['digest'])){throw new RuntimeException('Release digest mismatch');}
file_put_contents($root.'/.build/panel-rc1.tar.gz',$bytes);
file_put_contents($root.'/.build/panel-rc1-provenance.json',json_encode(['release'=>$release['tag_name'],'url'=>$asset['browser_download_url'],'sha256'=>$hash,'bytes'=>strlen($bytes),'github_digest_verified'=>!empty($asset['digest'])],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Official RC.1 archive verified: ',strlen($bytes)," bytes\n";
