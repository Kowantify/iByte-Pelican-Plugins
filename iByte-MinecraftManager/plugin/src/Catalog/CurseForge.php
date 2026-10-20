<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Catalog;

use IByte\MinecraftManager\Support\Compatibility;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\Settings;

final class CurseForge
{
    private const CLASS_IDS = ['mod' => 6, 'plugin' => 5, 'modpack' => 4471];

    private const LOADER_IDS = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    private const PLATFORMS = ['forge', 'fabric', 'quilt', 'neoforge', 'bukkit', 'spigot', 'paper', 'purpur', 'folia', 'velocity', 'bungeecord', 'waterfall'];

    public function __construct(private readonly JsonTransport $http) {}

    private function id(string $id): string
    {
        if (!preg_match('/\A[1-9][0-9]{0,12}\z/D', $id)) {
            throw new Failure('invalid_identifier');
        }

        return $id;
    }

    public function search(array $context): array
    {
        $this->platformFilter($context);
        $query = [
            'gameId' => 432, 'classId' => self::CLASS_IDS[$context['type']],
            'searchFilter' => $context['query'], 'index' => ($context['page'] - 1) * 20, 'pageSize' => 20,
            'sortField' => ['popular' => 2, 'downloads' => 6, 'updated' => 3][$context['sort'] ?? 'popular'] ?? throw new Failure('invalid_selection'), 'sortOrder' => 'desc',
        ];
        if ($context['version'] !== '') {
            $query['gameVersion'] = $context['version'];
        }
        if ($context['type'] !== 'plugin' && $context['loader'] !== '' && isset(self::LOADER_IDS[$context['loader']])) {
            $query['modLoaderType'] = self::LOADER_IDS[$context['loader']];
        }
        $body = $this->http->get('curseforge', '/v1/mods/search', $query);
        $items = [];
        foreach (Input::list($body, 'data', 20) as $hit) {
            $items[] = ['id' => $this->id((string) ($hit['id'] ?? '')), 'title' => Input::text($hit['name'] ?? '', 200),
                'description' => Input::text($hit['summary'] ?? ''), 'environment' => 'unknown',
                'icon' => Input::image($hit['logo']['thumbnailUrl'] ?? $hit['logo']['url'] ?? null),
                'downloads' => max(0, (int) ($hit['downloadCount'] ?? 0)), 'author' => Input::text($hit['authors'][0]['name'] ?? '', 100),
                'updated' => Input::text($hit['dateModified'] ?? '', 30)];
        }

        return ['items' => $items, 'total' => min(10000, (int) ($body['pagination']['totalCount'] ?? count($items))), 'page' => $context['page']];
    }

    public function releases(string $project, array $context, ?bool &$hasMore = null): array
    {
        $this->platformFilter($context);
        $query = ['pageSize' => 50, 'index' => ($context['release_page'] - 1) * 50];
        if ($context['version'] !== '') {
            $query['gameVersion'] = $context['version'];
        }
        if ($context['type'] !== 'plugin' && isset(self::LOADER_IDS[$context['loader']])) {
            $query['modLoaderType'] = self::LOADER_IDS[$context['loader']];
        }
        $body = $this->http->get('curseforge', '/v1/mods/' . $this->id($project) . '/files', $query);
        $result = [];
        $files = Input::list($body, 'data', 50);
        $hasMore = isset($body['pagination']['totalCount'])
            ? (int) $body['pagination']['totalCount'] > $context['release_page'] * 50 : count($files) === 50;
        foreach ($files as $file) {
            if ($context['experimental'] || ($file['releaseType'] ?? null) === 1) {
                $result[] = ['id' => $this->id((string) $file['id']), 'title' => Input::text($file['displayName'] ?? '', 200),
                    'game_versions' => array_values(array_filter($file['gameVersions'] ?? [], fn ($v) => !in_array(strtolower($v), [...self::PLATFORMS, 'client', 'server'], true) && !preg_match('/^Java\s*\d+/i', $v))),
                    'loaders' => array_values(array_intersect(array_map('strtolower', $file['gameVersions'] ?? []), self::PLATFORMS)),
                    'channel' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$file['releaseType'] ?? 0] ?? 'unknown'];
            }
        }

        $result = array_values(array_filter($result, static function ($release) use ($context): bool {
            if ($context['version'] !== '' && !in_array($context['version'], $release['game_versions'], true)) {
                return false;
            }

            return $context['loader'] === '' || !$release['loaders'] || Compatibility::accepts($context['loader'], $release['loaders'], $context['type']);
        }));

        return Compatibility::orderReleases($result);
    }

    public function exact(string $project, string $id): array
    {
        $meta = $this->http->get('curseforge', '/v1/mods/' . $this->id($project))['data'] ?? [];

        return $this->fileRelease($project, $id, $meta);
    }

    private function platformFilter(array $context): void
    {
        if ($context['type'] === 'plugin' && $context['loader'] !== '' && !Compatibility::accepts($context['loader'], ['bukkit'], 'plugin')) {
            throw new Failure('provider_platform_filter_unavailable');
        }
    }

    public function manifestRelease(string $project, string $id, ?array $meta = null, ?array $file = null, ?bool $client = null): array
    {
        $meta ??= $this->http->get('curseforge', '/v1/mods/'.$this->id($project))['data'] ?? [];
        if ((string) ($meta['id'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        // Resource packs and shader packs are client assets, not required server
        // mods. Identify them before requesting a download or its authorization.
        if (in_array($meta['classId'] ?? null, [12, 6552], true)) {
            return ['skip' => 'client_asset', 'title' => Input::text($meta['name'] ?? $project, 200), 'project' => $project, 'id' => $id];
        }
        if (($meta['classId'] ?? null) !== 6) {
            throw new Failure('invalid_pack_file', ['project' => $project]);
        }
        $file ??= $this->http->get('curseforge', '/v1/mods/'.$this->id($project).'/files/'.$this->id($id))['data'] ?? [];
        if ((string) ($file['id'] ?? '') !== $id || (string) ($file['modId'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        if ($client ?? $this->clientOnly($file)) {
            return ['skip' => 'client_mod', 'title' => Input::text($meta['name'] ?? $file['fileName'], 200), 'project' => $project, 'id' => $id];
        }

        return [...$this->fileRelease($project, $id, $meta, $file), 'artifact' => $this->fileArtifact($project, $id, $file)];
    }

    /** Exact manifest identities, in small bounded API batches, without N+1 lookups. */
    public function manifestReleases(array $manifest, callable $guard): iterable
    {
        foreach (array_chunk($manifest, 50) as $chunk) {
            $guard();
            $pairs = [];
            foreach ($chunk as $entry) {
                if (($entry['required'] ?? true) === false) {
                    continue;
                }
                $pairs[] = [$this->id((string) ($entry['projectID'] ?? '')), $this->id((string) ($entry['fileID'] ?? ''))];
            }
            if (!$pairs) {
                continue;
            }
            $projectIds = array_values(array_unique(array_column($pairs, 0)));
            $projects = [];
            foreach (Input::list($this->http->post('curseforge', '/v1/mods', ['modIds' => array_map('intval', $projectIds)]), 'data', 50) as $meta) {
                $id = $this->id((string) ($meta['id'] ?? ''));
                if (!in_array($id, $projectIds, true) || isset($projects[$id])) {
                    throw new Failure('provider_schema_changed');
                }
                $projects[$id] = $meta;
            }
            $fileIds = [];
            foreach ($pairs as [$project, $id]) {
                $meta = $projects[$project] ?? throw new Failure('exact_release_unavailable');
                if (($meta['classId'] ?? null) === 6) {
                    $fileIds[] = $id;
                } elseif (!in_array($meta['classId'] ?? null, [12, 6552], true)) {
                    throw new Failure('invalid_pack_file', ['project' => $project]);
                }
            }
            $files = [];
            if ($fileIds) {
                foreach (Input::list($this->http->post('curseforge', '/v1/mods/files', ['fileIds' => array_values(array_unique(array_map('intval', $fileIds)))]), 'data', 50) as $file) {
                    $id = $this->id((string) ($file['id'] ?? ''));
                    if (!in_array($id, $fileIds, true) || isset($files[$id])) {
                        throw new Failure('provider_schema_changed');
                    }
                    $files[$id] = $file;
                }
            }
            foreach ($pairs as [$project, $id]) {
                if (($projects[$project]['classId'] ?? null) === 6 && (!isset($files[$id]) || (string) ($files[$id]['modId'] ?? '') !== $project)) {
                    throw new Failure('exact_release_unavailable');
                }
            }
            $clients = $this->clientOnlyBatch($files);
            $guard();
            foreach ($pairs as [$project, $id]) {
                yield $this->manifestRelease($project, $id, $projects[$project], $files[$id] ?? null, $clients[$id] ?? false);
            }
        }
    }

    private function clientOnlyBatch(array $files): array
    {
        if (!app(Settings::class)->providerEnabled('modrinth')) {
            return [];
        }
        $hashes = [];
        foreach ($files as $id => $file) {
            $hash = collect($file['hashes'] ?? [])->firstWhere('algo', 1)['value'] ?? '';
            if (preg_match('/\A[0-9a-f]{40}\z/Di', $hash)) {
                $hashes[$id] = strtolower($hash);
            }
        }
        if (!$hashes) {
            return [];
        }
        try {
            $versions = $this->http->post('modrinth', '/v2/version_files', ['hashes' => array_values(array_unique($hashes)), 'algorithm' => 'sha1']);
            $matches = [];
            $projectIds = [];
            $clients = [];
            foreach ($hashes as $id => $hash) {
                $version = $versions[$hash] ?? [];
                $match = collect($version['files'] ?? [])->first(fn ($entry) => strtolower($entry['hashes']['sha1'] ?? '') === $hash && ($entry['size'] ?? null) === ($files[$id]['fileLength'] ?? null));
                if (!$match) {
                    continue;
                }
                if (in_array($version['environment'] ?? null, ['client_only', 'singleplayer_only'], true)) {
                    $clients[$id] = true;

                    continue;
                }
                $project = Input::id($version['project_id'] ?? '');
                $matches[$id] = $project;
                $projectIds[$project] = $project;
            }
            if ($projectIds) {
                $projects = $this->http->get('modrinth', '/v2/projects', ['ids' => json_encode(array_values($projectIds), JSON_THROW_ON_ERROR)]);
                foreach ($projects as $project) {
                    foreach ($matches as $id => $wanted) {
                        if (($project['id'] ?? null) === $wanted && ($project['server_side'] ?? null) === 'unsupported') {
                            $clients[$id] = true;
                        }
                    }
                }
            }

            return $clients;
        } catch (Failure) {
            // Unknown metadata/outages never justify omitting a required server mod.
            return [];
        }
    }

    private function clientOnly(array $file): bool
    {
        if (!app(Settings::class)->providerEnabled('modrinth')) {
            return false;
        }
        $sha1 = collect($file['hashes'] ?? [])->firstWhere('algo', 1)['value'] ?? '';
        if (!preg_match('/\A[0-9a-f]{40}\z/Di', $sha1)) {
            return false;
        }
        try {
            // A matching name or slug is insufficient: identify the exact bytes.
            $version = $this->http->get('modrinth', '/v2/version_file/'.strtolower($sha1), ['algorithm' => 'sha1']);
            $match = collect($version['files'] ?? [])->first(fn ($entry) => strtolower($entry['hashes']['sha1'] ?? '') === strtolower($sha1)
                && ($entry['size'] ?? null) === ($file['fileLength'] ?? null));
            if (!$match) {
                return false;
            }
            if (in_array($version['environment'] ?? null, ['client_only', 'singleplayer_only'], true)) {
                return true;
            }
            $project = $this->http->get('modrinth', '/v2/project/'.Input::id($version['project_id'] ?? ''));

            return ($project['id'] ?? null) === ($version['project_id'] ?? null) && ($project['server_side'] ?? null) === 'unsupported';
        } catch (Failure) {
            // Missing hashes, unknown environments and outages never justify
            // omitting a required server mod or bypassing its download policy.
            return false;
        }
    }

    private function fileRelease(string $project, string $id, array $meta, ?array $file = null): array
    {
        $file ??= $this->http->get('curseforge', '/v1/mods/' . $this->id($project) . '/files/' . $this->id($id))['data'] ?? [];
        if ((string) ($meta['id'] ?? '') !== $project || (string) ($file['id'] ?? '') !== $id || (string) ($file['modId'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        $hashes = [];
        foreach (Input::list($file, 'hashes', 10) as $hash) {
            $algorithm = [1 => 'sha1', 2 => 'md5'][$hash['algo'] ?? 0] ?? null;
            if ($algorithm) {
                $hashes[$algorithm] = $hash['value'];
            }
        }

        return [
            'provider' => 'curseforge', 'project' => $project, 'id' => $id,
            'project_title' => Input::text($meta['name'] ?? '', 200),
            'title' => Input::text($file['displayName'] ?? $id, 200), 'version' => $id,
            'channel' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$file['releaseType'] ?? 0] ?? 'unknown',
            'game_versions' => array_values(array_filter(Input::list($file, 'gameVersions', 100), fn ($v) => !in_array(strtolower($v), [...self::PLATFORMS, 'client', 'server'], true) && !preg_match('/^Java\s*\d+/i', $v))),
            'loaders' => array_values(array_intersect(array_map('strtolower', $file['gameVersions']), self::PLATFORMS)), 'environment' => 'unknown',
            'project_type' => array_search($meta['classId'] ?? null, self::CLASS_IDS, true) ?: 'unknown',
            'files' => [['id' => $id, 'name' => Input::filename($file['fileName'] ?? ''), 'size' => $file['fileLength'] ?? null,
                'hashes' => $hashes, 'primary' => true]],
            'server_pack_id' => isset($file['serverPackFileId']) ? (string) $file['serverPackFileId'] : null,
            'dependencies' => Input::list($file, 'dependencies', 500),
            'source_resolution' => empty($file['downloadUrl']) ? 'curseforge-cdn' : 'provider',
            'compatibility' => 'unknown', 'reason' => 'preflight_required',
        ];
    }

    public function artifact(string $project, string $id, bool $serverPack = false): array
    {
        $file = $this->http->get('curseforge', '/v1/mods/'.$this->id($project).'/files/'.$this->id($id))['data'] ?? [];
        if ((string) ($file['id'] ?? '') !== $id || (string) ($file['modId'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        if ($serverPack && !empty($file['serverPackFileId'])) {
            return $this->artifact($project, (string) $file['serverPackFileId']);
        }

        return $this->fileArtifact($project, $id, $file);
    }

    private function fileArtifact(string $project, string $id, array $file): array
    {
        if ((string) ($file['id'] ?? '') !== $id || (string) ($file['modId'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        $hashes = [];
        foreach ($file['hashes'] ?? [] as $hash) {
            $algorithm = [1 => 'sha1', 2 => 'md5'][$hash['algo'] ?? 0] ?? null;
            if ($algorithm) {
                $hashes[$algorithm] = $hash['value'];
            }
        }
        $name = Input::filename($file['fileName']);
        $url = $file['downloadUrl'] ?? '';
        if (!$url) {
            // Same exact public file resolution used by the researched Pelican
            // installer. Identity, size and publisher SHA-1 are mandatory; never
            // substitute another release or send the API credential to the CDN.
            if (!is_int($file['fileLength'] ?? null) || $file['fileLength'] <= 0
                || !preg_match('/\A[0-9a-f]{40}\z/Di', $hashes['sha1'] ?? '')) {
                throw new Failure('strong_integrity_required');
            }
            $fileId = (int) $this->id($id);
            $url = 'https://edge.forgecdn.net/files/'.intdiv($fileId, 1000).'/'.($fileId % 1000).'/'.rawurlencode($name);
        }

        return ['url' => $url, 'name' => $name, 'size' => $file['fileLength'] ?? null, 'hashes' => $hashes,
            'source_resolution' => empty($file['downloadUrl']) ? 'curseforge-cdn' : 'provider'];
    }

    public function dependency(array $dependency, array $context): array
    {
        $project = $this->id((string) ($dependency['modId'] ?? ''));
        $id = $this->releases($project, $context)[0]['id'] ?? throw new Failure('dependency_exact_release_required');

        return $this->exact($project, $id);
    }
}
