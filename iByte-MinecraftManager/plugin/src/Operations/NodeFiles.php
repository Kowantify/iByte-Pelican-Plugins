<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\EachPromise;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\UrlPolicy;
use Illuminate\Http\Client\RequestException;

/** Stock Wings transport: downloads, metadata, bounded JSON and native file operations. */
class NodeFiles
{
    private array $directories = [];

    public function __construct(protected Server $server) {}

    protected function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }

    public function pullDirect(array $downloads, callable $progress): void
    {
        $done = 0;
        $total = count($downloads);
        $failure = null;
        $this->mkdirMany(array_values(array_unique(array_filter(array_map(static fn ($item) => dirname($item['path']), $downloads), static fn ($dir) => $dir !== '.'))));
        $handler = new CurlMultiHandler();
        $requests = (function () use ($downloads, $progress, $handler, &$failure, &$done, $total) {
            foreach ($downloads as $item) {
                if ($failure) {
                    break;
                }
                try {
                    Input::nodePath($item['path']);
                    UrlPolicy::host($item['url'], UrlPolicy::ARTIFACT_HOSTS);
                    $progress(['phase' => 'download', 'files' => $done, 'total' => $total, 'file' => $item['name'] ?? basename($item['path'])]);
                    $request = $this->files()->getHttpClient()->setHandler($handler)->timeout(300)->withOptions([
                        // Do not abort foreground HTTP on cancellation: Wings would keep writing.
                        // Finish the current window, then stop; only temporary paths are affected.
                        'progress' => static function () use ($progress, &$failure, &$done, $total) {
                            if ($failure) {
                                return;
                            }
                            try {
                                $progress(['phase' => 'download', 'files' => $done, 'total' => $total, 'file' => '']);
                            } catch (\Throwable $error) {
                                $failure = $error;
                            }
                        },
                    ])->async()->post('/api/servers/'.$this->server->uuid.'/files/pull', [
                        'url' => $item['url'], 'root' => '/'.(dirname($item['path']) === '.' ? '' : dirname($item['path'])),
                        'file_name' => basename($item['path']), 'foreground' => true,
                    ]);
                    yield $item['path'] => method_exists($request, 'buildPromise') ? $request->buildPromise() : $request;
                } catch (\Throwable $error) {
                    $failure ??= $error;
                    break;
                }
            }
        })();
        $byPath = array_column($downloads, null, 'path');
        $pool = new EachPromise($requests, [
            'concurrency' => 3,
            'fulfilled' => static function ($response, $path) use (&$failure, &$done, $total, $progress, $byPath): void {
                try {
                    $response->throw();
                    if ($response->status() !== 200) {
                        throw new Failure('artifact_download_failed');
                    }
                    $entry = $response->json();
                    $item = $byPath[$path];
                    if (!is_array($entry) || !($entry['file'] ?? false) || ($entry['symlink'] ?? false) || !isset($entry['size']) || isset($item['size']) && (int) $entry['size'] !== $item['size']) {
                        throw new Failure('artifact_download_failed', ['file' => $item['name'] ?? basename($path)]);
                    }
                    if (!$failure) {
                        $progress(['phase' => 'download', 'files' => ++$done, 'total' => $total, 'file' => $item['name'] ?? basename($path)]);
                    }
                } catch (\Throwable $error) {
                    $failure ??= $error;
                }
            },
            'rejected' => static function ($error) use (&$failure): void {
                $failure ??= $error instanceof \Throwable ? $error : new Failure('artifact_download_failed');
            },
        ]);
        $pool->promise()->wait();
        if ($failure) {
            throw $failure;
        }
    }

    /** Settle each bounded batch before propagating errors or cancellation. */
    private function metadataBatch(array $items, callable $request, callable $accept, ?callable $guard = null): void
    {
        $handler = new CurlMultiHandler();
        foreach (array_chunk($items, 4, true) as $batch) {
            $guard && $guard();
            $failure = null;
            $requests = (function () use ($batch, $request, $handler) {
                foreach ($batch as $key => $item) {
                    $pending = $request($item, $handler);
                    yield $key => method_exists($pending, 'buildPromise') ? $pending->buildPromise() : $pending;
                }
            })();
            (new EachPromise($requests, [
                'concurrency' => 4,
                'fulfilled' => static function ($response, $key) use ($accept, &$failure): void {
                    try {
                        $accept($response, $key);
                    } catch (\Throwable $error) {
                        $failure ??= $error;
                    }
                },
                'rejected' => static function ($error, $key) use ($accept, &$failure): void {
                    try {
                        if ($error instanceof RequestException) {
                            $accept($error->response, $key);
                        } else {
                            throw $error instanceof \Throwable ? $error : new Failure('node_unavailable');
                        }
                    } catch (\Throwable $cause) {
                        $failure ??= $cause;
                    }
                },
            ]))->promise()->wait();
            if ($failure) {
                throw $failure;
            }
            $guard && $guard();
        }
    }

    /** Fresh metadata only; never cache listings across installation phases. */
    private function listings(array $parents, bool $allowMissing = false, ?callable $guard = null): array
    {
        $result = [];
        $items = array_combine($parents, $parents);
        $this->metadataBatch($items,
            fn ($parent, $handler) => $this->files()->getHttpClient()->setHandler($handler)->async()
                ->get('/api/servers/'.$this->server->uuid.'/files/list-directory', ['directory' => '/'.($parent === '.' ? '' : $parent)]),
            static function ($response, $parent) use (&$result, $allowMissing): void {
                if ($allowMissing && $response->status() === 404) {
                    $result[$parent] = [];

                    return;
                }
                $response->throw();
                $rows = $response->json();
                if (!is_array($rows) || !array_is_list($rows)) {
                    throw new Failure('node_unavailable');
                }
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['name']) || !is_string($row['name'])) {
                        throw new Failure('node_unavailable');
                    }
                }
                $result[$parent] = $rows;
            }, $guard);

        return $result;
    }

    public function tree(string $root, callable $guard): array
    {
        Input::nodePath($root);
        $pending = ['' => 0];
        $result = [];
        $seen = [];
        $count = 0;
        $bytes = 0;
        while ($pending) {
            $batch = array_slice($pending, 0, 4, true);
            $pending = array_diff_key($pending, $batch);
            $parents = [];
            foreach ($batch as $relative => $depth) {
                if ($depth > 16) {
                    throw new Failure('unsafe_path');
                }
                $parents[] = $root.($relative === '' ? '' : '/'.$relative);
            }
            $listings = $this->listings($parents, false, $guard);
            foreach ($batch as $relative => $depth) {
                foreach ($listings[$root.($relative === '' ? '' : '/'.$relative)] as $entry) {
                    if (++$count > 10000) {
                        throw new Failure('archive_entry_limit');
                    }
                    $name = Input::filename($entry['name']);
                    $path = ($relative === '' ? '' : $relative.'/').$name;
                    Input::path($path);
                    $key = mb_strtolower($path, 'UTF-8');
                    if (isset($seen[$key])) {
                        throw new Failure('archive_duplicate');
                    }
                    $seen[$key] = true;
                    if ($entry['symlink'] ?? false) {
                        throw new Failure('archive_special_file');
                    }
                    if ($entry['directory'] ?? false) {
                        $pending[$path] = $depth + 1;

                        continue;
                    }
                    if (!($entry['file'] ?? false) || !is_numeric($entry['size'] ?? null) || (int) $entry['size'] < 0) {
                        throw new Failure('archive_special_file');
                    }
                    $bytes += (int) $entry['size'];
                    if ($bytes > 2147483648) {
                        throw new Failure('pack_size_limit');
                    }
                    $result[$path] = $entry;
                }
            }
        }

        return $result;
    }

    public function checkSizes(array $files, ?callable $progress = null): void
    {
        $done = 0;
        $guard = $progress ? function () use ($progress, &$done, $files): void {
            $progress($done, count($files));
        } : null;
        $entries = $this->entries(array_keys($files), $guard, function (string $path, array $entry) use ($files, $progress, &$done): void {
            if (!($entry['file'] ?? false) || ($entry['symlink'] ?? false) || !isset($entry['size']) || (int) $entry['size'] !== $files[$path]['size']) {
                throw new Failure('size_mismatch', ['file' => $path]);
            }
            $done++;
            $progress && $progress($done, count($files));
        });
        foreach ($files as $path => $_) {
            if (!isset($entries[$path])) {
                throw new Failure('size_mismatch', ['file' => $path]);
            }
        }
        $progress && $progress($done, count($files));
    }

    public function assertStopped(): void
    {
        $fresh = $this->server->fresh();
        if (!$fresh || $fresh->uuid !== $this->server->uuid || $fresh->node_id !== $this->server->node_id || $fresh->status !== null || $fresh->transfer) {
            throw new Failure('server_state_changed');
        }
        $state = app(DaemonServerRepository::class)->setServer($fresh)->getDetails(5)['state'] ?? 'unknown';
        if ($state !== 'offline') {
            throw new Failure($state === 'missing' ? 'node_unavailable' : 'server_must_be_stopped');
        }
    }

    public function mkdir(string $path): void
    {
        $this->mkdirMany([$path]);
    }

    public function mkdirMany(array $paths): void
    {
        $targets = [];
        foreach ($paths as $path) {
            Input::nodePath($path);
            for ($part = $path; $part !== '.' && !isset($this->directories[$part]); $part = dirname($part)) {
                $targets[$part] = true;
            }
        }
        $existing = $this->entries(array_keys($targets));
        $levels = [];
        foreach ($targets as $path => $_) {
            $entry = $existing[$path] ?? null;
            if ($entry) {
                if (!($entry['directory'] ?? false) || ($entry['file'] ?? true) || ($entry['symlink'] ?? false)) {
                    throw new Failure('node_path_collision', ['file' => $path]);
                }
                $this->directories[$path] = true;
            } else {
                $levels[substr_count($path, '/')][] = $path;
            }
        }
        ksort($levels);
        foreach ($levels as $pathsAtDepth) {
            $this->metadataBatch(array_combine($pathsAtDepth, $pathsAtDepth),
                fn ($path, $handler) => $this->files()->getHttpClient()->setHandler($handler)->async()
                    ->post('/api/servers/'.$this->server->uuid.'/files/create-directory', ['name' => basename($path), 'path' => dirname($path) === '.' ? '/' : '/'.dirname($path)]),
                function ($response, $path): void {
                    $response->throw();
                    $this->directories[$path] = true;
                });
        }
    }

    public function extract(string $archive, string $directory, ?callable $progress = null): void
    {
        if ($directory === '.') {
            Input::nodePath($archive);
            $this->files()->decompressFile('/', $archive)->throw();

            return;
        }
        Input::nodePath($directory);
        $progress && $progress('archive-stage');
        $this->mkdir($directory);
        $name = '.imm-'.bin2hex(random_bytes(16)).'.zip';
        $target = $directory.'/'.$name;
        $this->moveMany([['from' => $archive, 'to' => $target]]);
        $progress && $progress('extract');
        $this->files()->decompressFile('/'.$directory, $name)->throw();
        $progress && $progress('archive-stage');
        $this->removeMany([$target]);
    }

    public function entries(array $paths, ?callable $guard = null, ?callable $found = null): array
    {
        $wanted = [];
        $result = [];
        $requested = array_fill_keys($paths, true);
        $missing = [];
        foreach ($paths as $path) {
            Input::nodePath($path);
            for ($target = $path; $target !== '.'; $target = dirname($target)) {
                $wanted[dirname($target)][basename($target)] = $target;
            }
        }
        $levels = [];
        foreach ($wanted as $parent => $names) {
            $levels[$parent === '.' ? -1 : substr_count($parent, '/')][] = $parent;
        }
        ksort($levels);
        foreach ($levels as $parents) {
            $available = [];
            foreach ($parents as $parent) {
                if (isset($missing[$parent])) {
                    foreach ($wanted[$parent] as $path) {
                        $missing[$path] = true;
                    }
                } else {
                    $available[] = $parent;
                }
            }
            foreach ($this->listings($available, true, $guard) as $parent => $rows) {
                $names = $wanted[$parent];
                foreach ($rows as $row) {
                    if (!isset($names[$row['name']])) {
                        continue;
                    }
                    $path = $names[$row['name']];
                    if (isset($wanted[$path]) && (!($row['directory'] ?? false) || ($row['symlink'] ?? false))) {
                        throw new Failure('node_path_collision', ['file' => $path]);
                    }
                    if (isset($requested[$path])) {
                        $result[$path] = $row;
                        $found && $found($path, $row);
                    }
                    unset($names[$row['name']]);
                }
                foreach ($names as $path) {
                    $missing[$path] = true;
                }
            }
        }

        return $result;
    }

    public function moveMany(array $moves, ?callable $progress = null): void
    {
        foreach (array_chunk($moves, 100) as $chunk) {
            $targets = [];
            foreach ($chunk as $move) {
                Input::nodePath($move['from']);
                Input::nodePath($move['to']);
                $targets[] = dirname($move['to']);
            }
            $progress && $progress('directories');
            $this->mkdirMany(array_values(array_filter(array_unique($targets), static fn ($path) => $path !== '.')));
            $progress && $progress('apply');
            $this->files()->renameFiles('/', $chunk)->throw();
        }
    }

    public function removeMany(array $paths): void
    {
        foreach (array_chunk($paths, 100) as $chunk) {
            foreach ($chunk as $path) {
                Input::nodePath($path);
            } $this->files()->deleteFiles('/', $chunk)->throw();
        }
    }

    /** Pack manifests are the only server file contents read through the Panel. */
    public function readJson(string $path, int $limit = 4194304): array
    {
        Input::nodePath($path);
        $response = $this->files()->getHttpClient()->timeout(20)->withOptions(['stream' => true])
            ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path])->throw();
        $body = $response->toPsrResponse()->getBody();
        $json = '';
        try {
            while (!$body->eof()) {
                $json .= $body->read(65536);
                if (strlen($json) > $limit) {
                    throw new Failure('invalid_pack_manifest');
                }
            }
        } finally {
            $body->close();
        }
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('invalid_pack_manifest');
        }
        if (!is_array($manifest)) {
            throw new Failure('invalid_pack_manifest');
        }

        return $manifest;
    }
}
