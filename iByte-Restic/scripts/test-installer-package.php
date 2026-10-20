<?php

declare(strict_types=1);

// Isolated package-layout fixtures, never an operator's node or release folder.
$project = dirname(__DIR__);
$fixture = $project.'/.build/installer-package-'.bin2hex(random_bytes(6));
foreach (['scripts', '.build/release-artifacts', 'release'] as $directory) {
    mkdir($fixture.'/'.$directory, 0755, true);
}
copy($project.'/scripts/bootstrap-wings.sh', $fixture.'/scripts/bootstrap-wings.sh');
$nodeFiles = [];
foreach (['amd64', 'arm64'] as $arch) {
    foreach (['ibyte-pelican-wings-restic-v1.0.0-rc.1-v3.0.0-preview.2-linux-', 'ibyte-node-tool-v3.0.0-preview.2-linux-', 'restic-0.19.1-linux-'] as $prefix) {
        $nodeFiles[] = $prefix.$arch;
    }
}
$files = array_merge($nodeFiles, ['install-wings-restic.sh', 'NODE-SHA256SUMS']);
foreach ($files as $file) {
    file_put_contents($fixture.'/.build/release-artifacts/'.$file, 'fixture '.$file);
}
$package = function (string $url) use ($fixture, $project, $nodeFiles) {
    $root = $fixture;
    $releaseUrl = $url;
    $nodeLicenses = ['Fixture' => 'fixture-license'];
    require $project.'/scripts/package-installer.php';
};
$assert = function (bool $condition, string $message) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$wings = $fixture.'/release/Wings Installer';
try {
    $package('');
    $assert(is_file($wings.'/install.sh') && str_contains(file_get_contents($wings.'/README.md'), 'fixture-license') && count(scandir($wings.'/payload')) === 10, 'unconfigured package contains script, instructions/licenses and exactly eight local payload files');
    $same = true;
    foreach ($files as $file) {
        $same = $same && hash_file('sha256', $wings.'/payload/'.$file) === hash_file('sha256', $fixture.'/.build/release-artifacts/'.$file);
    }
    $assert($same, 'every bundled binary, installer and manifest matches its build artifact');
    $before = hash_file('sha256', $wings.'/install.sh');
    $package('');
    $assert(hash_file('sha256', $wings.'/install.sh') === $before, 'repeat local packaging preserves deterministic installer pins');
    $package('https://github.com/fixture/addon/releases/download/v3.0.0-preview.2');
    $assert(! is_dir($wings.'/payload') && str_contains(file_get_contents($wings.'/install.sh'), "URL='https://github.com/fixture/addon/releases/download/"), 'configured GitHub package is small and removes only generated local payload');
    $package('');
    $assert(is_dir($wings.'/payload') && hash_file('sha256', $wings.'/install.sh') === $before, 'switching back to unpublished mode restores self-contained packaging');
    file_put_contents($wings.'/payload/operator-file', 'preserve');
    $refused = false;
    try {
        $package('https://github.com/fixture/addon/releases/download/v3.0.0-preview.2');
    } catch (RuntimeException $exception) {
        $refused = str_contains($exception->getMessage(), 'Unexpected content');
    }
    $assert($refused && file_get_contents($wings.'/payload/operator-file') === 'preserve', 'mode switching refuses unexpected files without deleting them');
} finally {
    // Exact files created by this fixture only; no recursive directory deletion.
    $cleanup = [$wings.'/payload/operator-file', $wings.'/install.sh', $wings.'/README.md', $fixture.'/scripts/bootstrap-wings.sh'];
    foreach ($files as $file) {
        $cleanup[] = $wings.'/payload/'.$file;
        $cleanup[] = $fixture.'/.build/release-artifacts/'.$file;
    }
    foreach ($cleanup as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$wings.'/payload', $wings, $fixture.'/release', $fixture.'/scripts', $fixture.'/.build/release-artifacts', $fixture.'/.build', $fixture] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
