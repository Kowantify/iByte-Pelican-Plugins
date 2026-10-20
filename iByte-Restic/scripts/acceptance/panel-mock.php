<?php

// Empty-node control-plane fixture, bound only to loopback by systemd.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/api/remote/servers') {
    $uuid = '11111111-1111-4111-8111-111111111111';
    echo json_encode(['data' => [['uuid' => $uuid, 'settings' => ['uuid' => $uuid, 'id' => 1, 'meta' => ['name' => 'Disposable offline fixture'], 'suspended' => false, 'environment' => new stdClass, 'build' => ['memory_limit' => 128, 'disk_space' => 64], 'allocations' => ['default' => ['ip' => '127.0.0.1', 'port' => 25565], 'mappings' => new stdClass], 'container' => ['image' => 'busybox:latest'], 'egg' => ['id' => $uuid, 'file_denylist' => []]], 'process_configuration' => ['startup' => ['done' => []], 'stop' => ['type' => 'signal', 'value' => 'SIGTERM']]]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]);
} elseif ($path === '/api/remote/servers/reset' || $path === '/api/remote/activity') {
    http_response_code(204);
} else {
    http_response_code(404);
    echo '{}';
}
