<?php

// Only for the dedicated disposable Linux distribution.
if (getenv('IBYTE_DISPOSABLE_LINUX') !== '1' || ! is_file('/opt/ibyte-acceptance/fixture') || PHP_OS_FAMILY !== 'Linux') {
    exit(1);
}
$path = '/etc/pelican/config.yml';
// Keep Go's distinction between empty maps and empty sequences intact.
$original = file_get_contents('/opt/ibyte-acceptance/systemd-node/installer/wings-config.backup');
$replacement = "  ssl:\n    enabled: true\n    cert: /opt/ibyte-acceptance/systemd-node/tls.crt\n    key: /opt/ibyte-acceptance/systemd-node/tls.key\n";
$config = preg_replace('/^  ssl:\n(?:^    [^\n]*\n)*/m', $replacement, $original, count: $matches);
if ($matches !== 1) {
    throw new RuntimeException('Unexpected disposable fixture YAML; refusing to guess.');
}
file_put_contents($path, $config);
chmod($path, 0600);
