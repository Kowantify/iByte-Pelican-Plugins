<?php
$file='/opt/imm/wings.yml';require '/opt/imm-panel/vendor/autoload.php';$config=Symfony\Component\Yaml\Yaml::parseFile($file);file_put_contents($file,Symfony\Component\Yaml\Yaml::dump($config,8,2,Symfony\Component\Yaml\Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
