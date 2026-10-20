<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Modpacks;

use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

final class Mrpack
{
    public function parse(array $manifest): array
    {
        if (($manifest['formatVersion'] ?? null) !== 1 || ($manifest['game'] ?? null) !== 'minecraft'
            || !is_string($manifest['versionId'] ?? null) || !is_array($manifest['dependencies'] ?? null)) {
            throw new Failure('invalid_pack_manifest');
        }
        $dependencies = $manifest['dependencies'];
        if (!isset($dependencies['minecraft'])) {
            throw new Failure('pack_runtime_unknown');
        }
        $loaders = array_intersect(array_keys($dependencies), ['fabric-loader', 'quilt-loader', 'forge', 'neoforge']);
        if (count($loaders) > 1 || array_diff(array_keys($dependencies), ['minecraft', 'fabric-loader', 'quilt-loader', 'forge', 'neoforge'])) {
            throw new Failure('pack_runtime_unknown');
        }
        foreach ($dependencies as $version) {
            Input::id($version);
        }
        $files = $seen = [];
        $total = 0;
        foreach (Input::list($manifest, 'files', 10000) as $file) {
            $path = Input::path($file['path'] ?? '');
            $key = mb_strtolower($path, 'UTF-8');
            if (isset($seen[$key])) {
                throw new Failure('filename_collision');
            }
            $seen[$key] = true;
            $environment = $file['env']['server'] ?? 'required';
            if (!in_array($environment, ['required', 'optional', 'unsupported'], true)) {
                throw new Failure('pack_environment_unknown');
            }
            $size = $file['fileSize'] ?? null;
            $hash = $file['hashes']['sha512'] ?? null;
            if (!is_int($size) || $size < 0 || $size > 268435456 || !is_string($hash) || !preg_match('/\A[0-9a-f]{128}\z/Di', $hash)) {
                throw new Failure('invalid_pack_file');
            }
            $urls = Input::list($file, 'downloads', 5);
            if ($urls === [] || array_filter($urls, fn ($url) => !is_string($url) || strlen($url) > 4096)) {
                throw new Failure('invalid_pack_file');
            }
            // URLs stay untrusted. This parser never fetches them or approves destinations.
            if ($environment === 'unsupported') {
                continue;
            }
            $total += $size;
            if ($total > 2147483648) {
                throw new Failure('pack_size_limit');
            }
            $files[] = ['path' => $path, 'size' => $size, 'sha512' => strtolower($hash), 'optional' => $environment === 'optional'];
        }

        return ['id' => Input::id($manifest['versionId']), 'dependencies' => $dependencies, 'files' => $files];
    }

    public function overrides(array $paths): array
    {
        $common = $server = [];
        foreach ($paths as $path) {
            $path = Input::path($path);
            if (str_starts_with($path, 'overrides/')) {
                $common[substr($path, 10)] = $path;
            } elseif (str_starts_with($path, 'server-overrides/')) {
                $server[substr($path, 17)] = $path;
            }
        }

        return array_replace($common, $server);
    }
}
