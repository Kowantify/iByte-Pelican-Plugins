<?php
declare(strict_types=1);

require __DIR__.'/../panel-plugin/src/Support/Normalizer.php';
require __DIR__.'/../panel-plugin/src/Support/WingsClient.php';

use IByte\PrometheusExporter\Support\Normalizer;
use IByte\PrometheusExporter\Support\WingsClient;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$inventory = (object) ['uuid' => '79e7e26b-8895-4f8a-b844-0437ae5e683b', 'cpu' => 200, 'memory' => 1024, 'disk' => 2048,
    'name' => 'Fixture Server "quoted"', 'user' => (object) ['username' => 'owner_one']];
$raw = ['configuration' => ['uuid' => $inventory->uuid, 'environment' => ['SECRET' => 'never-export']],
    'state' => 'running', 'is_suspended' => false,
    'utilization' => ['cpu_absolute' => 175, 'memory_bytes' => 123456, 'disk_bytes' => 654321,
        'memory_limit_bytes' => 154349568, 'uptime' => 60000,
        'network' => ['rx_bytes' => 500, 'tx_bytes' => 250],
        'disk_io' => ['read_bytes' => 100, 'write_bytes' => 300]]];
$result = Normalizer::server($raw, $inventory);
check($result['cpu'] === 1.75, 'CPU must be relative to one core');
check($result['cpu_limit'] === 2.0, 'CPU limit conversion');
check($result['memory_limit'] === 1073741824.0, 'MiB conversion');
check(Normalizer::server($raw, $inventory, false)['memory_limit'] === round(1024 / 1.048576) * 1048576, 'Native decimal allocation conversion');
check($result['disk'] === 654321.0, 'Disk must remain bytes');
check($result['network_rx'] === 500.0 && $result['network_tx'] === 250.0, 'Network counters carried');
check($result['disk_read'] === 100.0 && $result['disk_write'] === 300.0, 'Disk I/O counters carried');
check($result['uptime'] === 60000.0, 'Uptime carried');
check($result['wings_memory_limit'] === 154349568.0, 'Wings memory limit carried');
check(!str_contains(json_encode($result), 'never-export'), 'Secrets must be discarded');
check($result['state'] === 'running', 'Running state');
check($result['name'] === 'Fixture Server "quoted"', 'Server name carried');
check($result['owner'] === 'owner_one', 'Owner username carried');
$raw['is_suspended'] = true;
check(Normalizer::server($raw, $inventory)['state'] === 'suspended', 'Suspension is distinct');
$raw['is_suspended'] = false;
$raw['state'] = 'offline';
$raw['utilization']['cpu_absolute'] = 0;
$raw['utilization']['memory_bytes'] = 0;
$result = Normalizer::server($raw, $inventory);
check($result['cpu'] === 0.0 && $result['memory'] === 0.0 && $result['disk'] > 0, 'Stopped usage');
foreach ([null, '123', -1, INF, NAN, [], true] as $invalid) {
    $raw['utilization']['cpu_absolute'] = $invalid;
    $raw['utilization']['network'] = ['rx_bytes' => $invalid];
    $raw['utilization']['uptime'] = $invalid;
    $result = Normalizer::server($raw, $inventory);
    check($result['cpu'] === null, 'Unknown/invalid CPU must be omitted');
    check($result['network_rx'] === null, 'Unknown/invalid network counter must be omitted');
    check($result['uptime'] === null, 'Unknown/invalid uptime must be omitted');
}
$inventory->cpu = 0;
$result = Normalizer::server($raw, $inventory);
check($result['cpu_limit'] === null && $result['unlimited_cpu'] === true, 'Unlimited allocation');
$inventory->cpu = null;
check(Normalizer::server($raw, $inventory)['unlimited_cpu'] === null, 'Unknown allocation is not limited');
$raw['configuration']['uuid'] = 'forged';
check(Normalizer::server($raw, $inventory) === null, 'Reject foreign UUID');
$raw['configuration']['uuid'] = $inventory->uuid;
$inventory->name = "newline\ninjection";
$inventory->user = (object) ['username' => 'quote"owner'];
$cleaned = Normalizer::server($raw, $inventory);
check($cleaned['name'] === "newline\ninjection" && $cleaned['owner'] === 'quote"owner',
    'Raw name/owner stored verbatim; exposition escaping happens at render');
$node = (object) ['id' => 7, 'scheme' => 'https', 'fqdn' => '10.20.30.40', 'daemon_connect' => 8080, 'daemon_token' => 'fixture'];
check(WingsClient::collect([$node], 1, 1)[7]['body'] === null, 'Unreachable node reports a null body without stalling');
$node->fqdn = 'example.com/path';
check(WingsClient::collect([$node], 1, 1)[7]['body'] === null, 'Malformed node host is skipped before any request');
$node->scheme = 'http';
$node->fqdn = '10.20.30.40';
check(WingsClient::collect([$node], 1, 1)[7]['body'] === null, 'HTTP node skipped; daemon token never sent insecurely');
echo "PASS $checks normalization and address-policy checks\n";
