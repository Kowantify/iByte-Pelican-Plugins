<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Packages;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

final class Inventory
{
    public function scan(Server $server, string $contentType = 'mod'): array
    {
        $directory = match ($contentType) {
            'mod' => 'mods', 'plugin', 'proxy' => 'plugins', default => null,
        };
        if (!$directory) {
            return [];
        }
        $repository = app(DaemonFileRepository::class);
        $repository->setServer($server);
        $entries = $repository->getDirectory('/' . $directory);
        if (count($entries) > 1000) {
            throw new Failure('inventory_limit');
        }
        $result = [];
        foreach ($entries as $entry) {
            $name = Input::filename($entry['name'] ?? '');
            if (!($entry['file'] ?? false) || ($entry['symlink'] ?? false) || !preg_match('/\.jar(?:\.disabled)?\z/i', $name)) {
                continue;
            }
            $result[] = ['name' => $name, 'size' => (int) ($entry['size'] ?? 0),
                'status' => 'unknown', 'disabled' => str_ends_with($name, '.disabled')];
        }

        return $result;
    }
}
