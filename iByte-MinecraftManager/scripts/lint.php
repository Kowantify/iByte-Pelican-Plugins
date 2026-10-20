<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
foreach (['plugin', 'scripts'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.blade.php')) { $files[] = $file->getPathname(); }
    }
}
sort($files);
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { fwrite(STDERR, $output); exit(1); }
}
echo count($files), " PHP files passed syntax checks.\n";
