<?php

declare(strict_types=1);

// Maintainer tool: change the release identity together, never rewrite history.
$root = dirname(__DIR__);
$pins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
$current = $pins['release'];
$currentPanel = $pins['panel_release'] ?? $current;
$next = $argv[1] ?? '--check';
$panelOnly = ($argv[2] ?? '') === '--panel-only';
if ($next === '--check') {
    $plugin = json_decode(file_get_contents($root.'/panel-plugin/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($plugin['version'] !== $currentPanel || ! str_contains(file_get_contents($root.'/wings-addon/ibyte/restic/engine/engine.go'), 'const Version = "'.$current.'"')) {
        throw new RuntimeException('Release identities differ');
    }
    echo 'Release identities agree: Panel '.$currentPanel.', Wings '.$current.PHP_EOL;
    exit;
}
if (! preg_match('/^3\.\d+\.\d+(?:-preview\.[1-9]\d*)?$/D', $next) || version_compare($next, $current, '<=') || version_compare($next, $currentPanel, '<=')) {
    throw new RuntimeException('Supply a newer 3.x semantic version, optionally -preview.N');
}
if (! str_contains($next, '-preview.') && ! $pins['production_ready']) {
    throw new RuntimeException('Stable release refused: production acceptance is blocked');
}
if ($panelOnly) {
    $pins['panel_release'] = $next;
    file_put_contents($root.'/compatibility/versions.json', json_encode($pins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    $plugin = json_decode(file_get_contents($root.'/panel-plugin/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $plugin['version'] = $next;
    file_put_contents($root.'/panel-plugin/plugin.json', json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo 'Panel '.$currentPanel.' -> '.$next.'; compatible Wings remains '.$current.'. No node rebuild or handshake change.'.PHP_EOL;
    exit;
}
$files = [];
foreach (['scripts', 'panel-plugin', 'wings-addon', 'restic-runtime', 'compatibility', '.github'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'go', 'sh', 'json', 'yml', 'md', 'js'], true)) {
            $files[] = $file->getPathname();
        }
    }
}
foreach (['README.md', 'docs/INSTALL_PANEL.md', 'docs/INSTALL_WINGS.md', 'docs/UPGRADE.md', 'docs/ROLLBACK.md', 'docs/RETENTION.md', 'docs/RELEASE_NOTES.md', 'docs/ARTIFACTS.md'] as $file) {
    $files[] = $root.'/'.$file;
}
$changed = 0;
foreach ($files as $file) {
    $before = file_get_contents($file);
    $after = str_replace(array_unique([$current, $currentPanel]), $next, $before);
    if ($before !== $after) {
        file_put_contents($file, $after);
        $changed++;
    }
}
$pins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
$pins['panel_release'] = $next;
file_put_contents($root.'/compatibility/versions.json', json_encode($pins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$plugin = json_decode(file_get_contents($root.'/panel-plugin/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
$plugin['version'] = $next;
file_put_contents($root.'/panel-plugin/plugin.json', json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "$current -> $next: $changed source/installation files updated. Rebuild both node architectures and package into an empty version delivery directory; preserve old artifacts for rollback. Historical evidence/changelog/progress are unchanged.\n";
