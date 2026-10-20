<?php

declare(strict_types=1);

// With no publication target, ship an inspectable adjacent payload folder.
$template = file_get_contents($root.'/scripts/bootstrap-wings.sh');
$bootstrap = str_replace(
    ['__NODE_SHA__', '__INSTALLER_SHA__', '__DEFAULT_URL__'],
    [hash_file('sha256', $root.'/.build/release-artifacts/NODE-SHA256SUMS'), hash_file('sha256', $root.'/.build/release-artifacts/install-wings-restic.sh'), $releaseUrl],
    str_replace("\r\n", "\n", $template)
);
$directory = $root.'/release/Wings Installer';
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
if (is_link($directory)) {
    throw new RuntimeException('Refusing linked generated installer directory');
}
file_put_contents($directory.'/install.sh', $bootstrap);
$payload = $directory.'/payload';
$payloadFiles = array_merge(['install-wings-restic.sh', 'NODE-SHA256SUMS'], $nodeFiles);
if (is_link($payload)) {
    throw new RuntimeException('Refusing linked generated payload directory');
}
if ($releaseUrl === '') {
    if (! is_dir($payload)) {
        mkdir($payload, 0755);
    }
    foreach ($payloadFiles as $file) {
        if (is_link($payload.'/'.$file) || ! copy($root.'/.build/release-artifacts/'.$file, $payload.'/'.$file)) {
            throw new RuntimeException('Cannot bundle node artifact: '.$file);
        }
    }
    $mode = 'Bundled local files; no GitHub repository or network download is required.';
} else {
    // Remove only known generated payload files when switching packaging mode.
    // Refuse unexpected contents instead of recursively deleting a directory.
    if (is_dir($payload)) {
        $existing = array_values(array_diff(scandir($payload), ['.', '..']));
        foreach ($existing as $file) {
            if (! in_array($file, $payloadFiles, true) || ! is_file($payload.'/'.$file) || is_link($payload.'/'.$file)) {
                throw new RuntimeException('Unexpected content in generated payload folder: '.$file);
            }
        }
        foreach ($existing as $file) {
            unlink($payload.'/'.$file);
        }
        rmdir($payload);
    }
    $mode = 'GitHub download mode; release assets are fetched and verified automatically.';
}
file_put_contents($directory.'/README.md', "# Install on one Wings node\n\n".$mode."\n\nCopy this entire folder to the Wings node, then open a terminal in it and run:\n\n```bash\nsudo bash install.sh --allow-preview --node-id NUMBER\n```\n\nReplace NUMBER with this node's ID from Panel Admin > Nodes. Run on the Wings node, not a separate Panel machine. Keep payload/ next to install.sh when present. No hash entry, compiler, GitHub setup or --artifacts argument is needed. Existing Pelican Wings v1.0.0-rc.1 on Linux/systemd amd64/arm64 is required.\n\nCheck files without changing the node: `bash install.sh --verify-only`. Roll back: `sudo bash install.sh --allow-preview --node-id NUMBER --rollback`.\n\nThe installer checks compatibility, preserves config/repositories/game files, backs up Wings and rolls back a failed startup. This is a staging preview; real Linux/systemd acceptance is outstanding.\n");
echo 'Readable Wings bootstrap: ', strlen($bootstrap), " bytes\n";
foreach ($nodeLicenses as $name => $license) {
    file_put_contents($directory.'/README.md', "\n<details><summary>".$name." license</summary>\n\n```text\n".str_replace("\r\n", "\n", $license)."\n```\n</details>\n", FILE_APPEND);
}
echo $mode, "\n";
