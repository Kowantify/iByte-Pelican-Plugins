<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Catalog;

use IByte\MinecraftManager\Support\Compatibility;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

final class Modrinth
{
    public function __construct(private readonly JsonTransport $http) {}

    public function search(array $context): array
    {
        $facets = [['project_type:' . $context['type']]];
        if ($context['version'] !== '') {
            $facets[] = ['versions:' . $context['version']];
        }
        if ($context['loader'] !== '') {
            $facets[] = array_map(static fn ($loader) => 'categories:'.$loader, Compatibility::platforms($context['loader'], $context['type']));
        }
        $body = $this->http->get('modrinth', '/v2/search', [
            'query' => $context['query'], 'offset' => ($context['page'] - 1) * 20, 'limit' => 20,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => ['popular' => 'follows', 'downloads' => 'downloads', 'updated' => 'updated'][$context['sort'] ?? 'popular'] ?? throw new Failure('invalid_selection'),
        ]);
        $items = [];
        foreach (Input::list($body, 'hits', 20) as $hit) {
            $items[] = [
                'id' => Input::id($hit['project_id'] ?? ''), 'title' => Input::text($hit['title'] ?? '', 200),
                'description' => Input::text($hit['description'] ?? ''),
                'icon' => Input::image($hit['icon_url'] ?? null), 'downloads' => max(0, (int) ($hit['downloads'] ?? 0)),
                'author' => Input::text($hit['author'] ?? '', 100),
                'updated' => Input::text($hit['date_modified'] ?? '', 30),
                'environment' => Input::text($hit['server_side'] ?? 'unknown', 30),
            ];
        }

        return ['items' => $items, 'total' => (int) ($body['total_hits'] ?? count($items)), 'page' => $context['page']];
    }

    public function releases(string $project, array $context, ?bool &$hasMore = null): array
    {
        $query = ['include_changelog' => 'false'];
        if ($context['version'] !== '') {
            $query['game_versions'] = json_encode([$context['version']], JSON_THROW_ON_ERROR);
        }
        if ($context['loader'] !== '') {
            $query['loaders'] = json_encode(Compatibility::platforms($context['loader'], $context['type']), JSON_THROW_ON_ERROR);
        }
        $versions = $this->http->get('modrinth', '/v2/project/' . rawurlencode(Input::id($project)) . '/version', $query);
        if (!array_is_list($versions) || count($versions) > 10000) {
            throw new Failure('provider_schema_changed');
        }
        $result = [];
        foreach ($versions as $version) {
            if ($context['version'] !== '' && !in_array($context['version'], $version['game_versions'] ?? [], true)) {
                continue;
            }
            if ($context['loader'] !== '' && !Compatibility::accepts($context['loader'], $version['loaders'] ?? [], $context['type'])) {
                continue;
            }
            if ($context['experimental'] || ($version['version_type'] ?? '') === 'release') {
                $result[] = ['id' => Input::id($version['id'] ?? ''), 'title' => Input::text($version['name'] ?? '', 200),
                    'channel' => Input::text($version['version_type'] ?? 'unknown', 30),
                    'loaders' => $version['loaders'] ?? [], 'game_versions' => $version['game_versions'] ?? []];
            }
        }

        $result = Compatibility::orderReleases($result);
        $hasMore = count($result) > $context['release_page'] * 50;

        return array_slice($result, ($context['release_page'] - 1) * 50, 50);
    }

    public function exact(string $project, string $id): array
    {
        $projectMeta = $this->http->get('modrinth', '/v2/project/' . rawurlencode(Input::id($project)));
        $body = $this->http->get('modrinth', '/v2/version/' . rawurlencode(Input::id($id)));
        // Search returns immutable project IDs. A slug cannot replace the selected ID.
        if (($body['id'] ?? null) !== $id || ($body['project_id'] ?? null) !== $project || ($projectMeta['id'] ?? null) !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        $files = [];
        foreach (Input::list($body, 'files', 100) as $file) {
            $files[] = [
                'id' => $file['hashes']['sha512'] ?? $file['hashes']['sha1'] ?? '',
                'name' => Input::filename($file['filename'] ?? ''),
                'size' => $file['size'] ?? null, 'hashes' => $file['hashes'] ?? [],
                'primary' => (bool) ($file['primary'] ?? false),
            ];
        }

        $loaders = Input::list($body, 'loaders');
        $plugin = (bool) array_intersect($loaders, ['bukkit', 'spigot', 'paper', 'purpur', 'folia', 'sponge', 'velocity', 'bungeecord', 'waterfall']);
        $mod = (bool) array_intersect($loaders, ['fabric', 'forge', 'neoforge', 'quilt', 'liteloader', 'rift', 'risugami']);
        $types = ($projectMeta['project_type'] ?? '') === 'modpack' ? ['modpack']
            : ($plugin ? ($mod ? ['mod', 'plugin'] : ['plugin']) : [$projectMeta['project_type'] ?? 'unknown']);

        return [
            'provider' => 'modrinth', 'project' => $project, 'id' => $id,
            'project_title' => Input::text($projectMeta['title'] ?? '', 200),
            'title' => Input::text($body['name'] ?? $id, 200), 'version' => Input::text($body['version_number'] ?? $id, 128),
            'channel' => Input::text($body['version_type'] ?? 'unknown', 30),
            'game_versions' => Input::list($body, 'game_versions'), 'loaders' => $loaders,
            'files' => $files, 'dependencies' => Input::list($body, 'dependencies', 500),
            'environment' => Input::text($projectMeta['server_side'] ?? 'unknown', 30),
            'project_type' => Input::text($types[0], 30), 'project_types' => $types,
            'changelog' => Input::text($body['changelog'] ?? '', 4000),
            'compatibility' => 'unknown', 'reason' => 'preflight_required',
        ];
    }

    public function artifact(string $project, string $id): array
    {
        $body = $this->http->get('modrinth', '/v2/version/' . rawurlencode(Input::id($id)));
        if (($body['id'] ?? '') !== $id || ($body['project_id'] ?? '') !== $project) {
            throw new Failure('exact_release_unavailable');
        }
        $files = Input::list($body, 'files', 100);
        $primary = array_values(array_filter($files, fn ($file) => $file['primary'] ?? false));
        if (count($primary) === 1) {
            $file = $primary[0];
        } elseif (count($files) === 1) {
            $file = $files[0];
        } else {
            throw new Failure('artifact_ambiguous');
        }

        return ['url' => $file['url'] ?? '', 'name' => Input::filename($file['filename']), 'size' => $file['size'] ?? null, 'hashes' => $file['hashes'] ?? []];
    }

    public function dependency(array $dependency, array $context): array
    {
        $id = $dependency['version_id'] ?? null;
        $project = $dependency['project_id'] ?? null;
        if ($id) {
            $body = $this->http->get('modrinth', '/v2/version/' . rawurlencode(Input::id($id)));
            $project = $project ?: ($body['project_id'] ?? '');
        } elseif ($project) {
            $versions = $this->releases(Input::id($project), $context);
            $id = $versions[0]['id'] ?? throw new Failure('dependency_exact_release_required');
        } else {
            throw new Failure('dependency_exact_release_required');
        }
        $release = $this->exact(Input::id($project), Input::id($id));
        if (!in_array($context['version'], $release['game_versions'], true) || !Compatibility::releaseMatches($release, $context)
            || $release['environment'] === 'unsupported') {
            throw new Failure('dependency_conflict');
        }

        return $release;
    }
}
