<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$work = $root.'/.build/opcache-upgrade-'.bin2hex(random_bytes(5));
mkdir($work, 0700, true);
$file = $work.'/value.php';
$router = $work.'/router.php';
file_put_contents($file, '<?php return "old";');
touch($file, 315532800);
file_put_contents($router, '<?php header("Content-Type: application/json"); echo json_encode(["value"=>require __DIR__."/value.php", "opcache"=>opcache_get_status(false)["opcache_enabled"] ?? false]);');
$pipes = [];
$process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=0', '-d', 'opcache.file_update_protection=0', '-d', 'opcache.cache_id=imm-upgrade-'.basename($work), '-S', '127.0.0.1:8097', $router], [['pipe','r'], ['file',$work.'/stdout.log','a'], ['file',$work.'/stderr.log','a']], $pipes, $work);
if (!is_resource($process)) { throw new RuntimeException('Cannot start isolated OPcache HTTP fixture'); }
$fetch = static function (): array {
    $data = @file_get_contents('http://127.0.0.1:8097');
    if ($data === false) { throw new RuntimeException('Fixture not ready'); }
    return json_decode($data, true, 16, JSON_THROW_ON_ERROR);
};
try {
    $first = null;
    for ($i=0; $i<100; $i++) {
        try { $first = $fetch(); break; } catch (RuntimeException) { usleep(50000); }
    }
    if ($first !== ['value'=>'old','opcache'=>true]) { throw new RuntimeException('OPcache baseline unavailable: '.json_encode($first)); }
    file_put_contents($file, '<?php return "new";'); touch($file, 315532800);
    $stale = $fetch();
    if ($stale['value'] !== 'old') { throw new RuntimeException('Same-mtime stale opcode case not reproduced: '.json_encode($stale)); }
    touch($file, 1792206900);
    $fresh = $fetch();
    if ($fresh['value'] !== 'new') { throw new RuntimeException('Changed-mtime upgrade did not refresh: '.json_encode($fresh)); }
    file_put_contents($root.'/docs/evidence/opcache-upgrade.json', json_encode(['php'=>PHP_VERSION, 'opcache_enabled'=>true, 'baseline'=>$first['value'], 'updated_source_same_mtime'=>$stale['value'], 'updated_source_new_mtime'=>$fresh['value'], 'checks'=>3, 'scope'=>'Real persistent PHP HTTP server with OPcache enabled and separate requests; default timestamp validation, revalidate_freq=0; isolated test files, not production host'], JSON_PRETTY_PRINT)."\n");
    echo "PASS initial HTTP request caches old PHP source\nPASS replacing PHP with the same ZIP mtime continues executing old code across HTTP requests\nPASS changing the release mtime executes updated code\nRESULT 3 OPcache upgrade checks passed.\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_close($process);
}
