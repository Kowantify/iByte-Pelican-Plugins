<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

/** Payloads and temporary archive trees exist only on Wings. No file-byte recovery. */
final class DirectPreparation
{
    private array $metadata = [];

    private array $temporary = [];

    private int $sequence = 0;

    private bool $cleaned = false;

    public function __construct(private NodeFiles $node, private Operation $operation, private \Closure $progress) {}

    private function path(string $suffix): string
    {
        return '.download-'.$this->operation->id.'-'.(++$this->sequence).$suffix;
    }

    public function fetch(array $artifact): array
    {
        $path = $this->path('.zip');
        if ($this->node->entries([$path])) {
            throw new Failure('node_path_collision');
        }
        $this->temporary[] = $path;
        $this->node->pullDirect([['path' => $path, ...$artifact]], $this->progress);
        $entry = $this->node->entries([$path])[$path] ?? throw new Failure('artifact_download_failed');
        $this->metadata[$path] = ['remote' => $path, 'size' => (int) $entry['size'], 'expected_hashes' => $artifact['hashes'] ?? [], 'integrity' => 'size-checked'];

        return [...$this->metadata[$path], 'reference' => $path];
    }

    public function extract(string $archive): array
    {
        $root = $this->path('.part');
        if ($this->node->entries([$root])) {
            throw new Failure('node_path_collision');
        }
        $this->temporary[] = $root;
        ($this->progress)(['phase' => 'extract', 'files' => 0, 'file' => basename($archive)]);
        $this->node->extract($archive, $root, fn ($phase) => ($this->progress)(['phase' => $phase, 'files' => 0]));
        ($this->progress)(['phase' => 'scan', 'files' => 0]);
        $entries = $this->node->tree($root, fn () => ($this->progress)(['phase' => 'scan', 'files' => 0]));
        $result = [];
        foreach ($entries as $relative => $entry) {
            Input::path($relative);
            $remote = $root.'/'.$relative;
            $this->metadata[$remote] = ['remote' => $remote, 'size' => (int) $entry['size'], 'expected_hashes' => [], 'integrity' => 'size-checked'];
            $result[$relative] = $remote;
        }
        ($this->progress)(['phase' => 'scan', 'files' => count($result), 'total' => count($result), 'file' => '']);

        return $result;
    }

    public function describe(string $reference): array
    {
        return $this->metadata[$reference] ?? throw new Failure('invalid_pack_file');
    }

    public function manifest(string $reference): array
    {
        $meta = $this->describe($reference);
        if ($meta['size'] > 4194304) {
            throw new Failure('invalid_pack_manifest');
        }

        // The only payload contents the Panel reads are small pack manifest/index JSON.
        return $this->node->readJson($reference);
    }

    public function complete(array $files): array
    {
        $downloads = [];
        foreach ($files as $path => &$file) {
            if (isset($file['artifact'])) {
                $remote = (dirname($path) === '.' ? '' : dirname($path).'/').$this->path('.part');
                $file['remote'] = $remote;
                $file['expected_hashes'] = $file['artifact']['hashes'] ?? [];
                $this->temporary[] = $remote;
                $downloads[] = ['path' => $remote, ...$file['artifact']];
            }
            $file['integrity'] = 'size-checked';
        }
        unset($file);
        if (array_sum(array_column($files, 'size')) > 2147483648 || count($downloads) > 2000) {
            throw new Failure('pack_size_limit');
        }
        if ($downloads) {
            if ($this->node->entries(array_column($downloads, 'path'))) {
                throw new Failure('node_path_collision');
            }
            $this->node->pullDirect($downloads, $this->progress);
        }

        return $files;
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }
        // Only operation-generated paths, never final files, old backups or user directories.
        $this->node->removeMany(array_keys($this->node->entries($this->temporary)));
        $this->cleaned = true;
    }
}
