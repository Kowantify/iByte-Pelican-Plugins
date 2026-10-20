<?php
declare(strict_types=1);
require __DIR__.'/../panel-plugin/src/Support/WingsClient.php';
use IByte\PrometheusExporter\Support\WingsClient;
$ports = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$nodes = [];
foreach ($ports as $id => $port) {
    $host = $id === 5 ? 'wrong.fixture.test' : 'fixture.test';
    $nodes[] = (object) ['id' => $id, 'scheme' => 'https', 'fqdn' => $host,
        'daemon_connect' => $port, 'daemon_token' => 'disposable-fixture-token'];
}
$start = microtime(true);
$results = WingsClient::collect($nodes, 1, 10);
if (($results[0]['body'] ?? null) !== '[]') {
    throw new RuntimeException('Healthy TLS response failed: '.var_export($results[0] ?? null, true));
}
foreach ([1, 2, 3, 4, 5] as $id) {
    if ($results[$id]['body'] !== null) throw new RuntimeException("Unsafe response accepted: $id");
}
if (microtime(true) - $start > 2.5) throw new RuntimeException('Deadline/concurrency bound exceeded');
$nodes[0]->fqdn = 'changed.fixture.test';
// A renamed host must not produce a body: TLS name verification rejects it
// (the fixture CA only certifies fixture.test).
if (WingsClient::collect([$nodes[0]], 1, 1)[0]['body'] !== null) {
    throw new RuntimeException('Changed host must not yield a body');
}
echo "PASS healthy HTTPS, response limit, redirect rejection, deadline, HTTP failure, TLS name, changed address\n";
