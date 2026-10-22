<?php

namespace IByte\EggChanger\Changes;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Eggs\EggChangerService;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Scoped server-data cleanup against the native daemon file API.
 *
 * Deletes only top-level entries of THIS server's data root, and only entries
 * whose names were observed via the authenticated getDirectory('/') call —
 * never traverses parent roots, shared mounts or another server's data. Mount
 * point directories are left in place: Wings presents mounts inside the root,
 * and native deny rules protect special files; the native reinstall itself
 * repopulates a fresh root for the target egg.
 */
final class RootCleaner
{
    /** @var string[] entries that must never be deleted at the root */
    private static array $protectedEntries = ['.pelican'];

    /**
     * Delete top-level server data entries. Returns the deleted entry names.
     *
     * @return string[]
     */
    public function clean(Server $server): array
    {
        $repository = app(DaemonFileRepository::class)->setServer($server);

        $entries = $repository->getDirectory('/');

        $names = [];
        foreach ($entries as $entry) {
            $name = $this->entryName($entry);
            if ($name === null || $name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if (in_array($name, self::$protectedEntries, true)) {
                continue;
            }

            $names[] = $name;
        }

        if ($names === []) {
            return [];
        }

        $repository->deleteFiles('/', $names);

        Log::info('ibyte-egg-changer: cleaned server root', [
            'server_uuid' => $server->uuid,
            'entries' => count($names),
        ]);

        return $names;
    }

    /** @return string|null */
    private function entryName(mixed $entry): ?string
    {
        if (is_array($entry)) {
            return is_string($entry['name'] ?? null) ? $entry['name'] : null;
        }

        if (is_object($entry)) {
            $name = $entry->name ?? null;

            return is_string($name) ? $name : null;
        }

        return null;
    }

    /** Stable uuid helper for operation rows. */
    public static function newUuid(): string
    {
        /** @var string $uuid */
        $uuid = Uuid::uuid4()->toString();

        return Uuid::isValid($uuid) ? $uuid : (string) \Illuminate\Support\Str::uuid();
    }

    /** Keep the service import referenced for static-analyzer clarity. */
    public function nativeServiceClass(): string
    {
        return EggChangerService::class;
    }
}