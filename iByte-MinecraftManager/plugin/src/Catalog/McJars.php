<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Catalog;

use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

final class McJars
{
    public function __construct(private readonly JsonTransport $http) {}

    public function types(): array
    {
        $body = $this->http->get('mcjars', '/api/v2/types');
        $groups = $body['types'] ?? null;
        if (!is_array($groups) || count($groups) > 30) {
            throw new Failure('provider_schema_changed');
        }
        $types = [];
        foreach ($groups as $group => $entries) {
            if (!is_array($entries) || count($entries) > 200) {
                throw new Failure('provider_schema_changed');
            }
            foreach ($entries as $id => $entry) {
                if (!is_array($entry) || isset($types[$id])) {
                    throw new Failure('provider_schema_changed');
                }
                $types[$id] = [
                    'id' => Input::id($id), 'title' => Input::text($entry['name'] ?? $id, 100),
                    'description' => Input::text($entry['description'] ?? ''),
                    'group' => Input::text($group, 100), 'experimental' => (bool) ($entry['experimental'] ?? false),
                    'deprecated' => (bool) ($entry['deprecated'] ?? false),
                    'installable' => null, 'reason' => 'per_build_preflight_required',
                ];
            }
        }
        if ($types === []) {
            throw new Failure('provider_schema_changed');
        }

        return array_values($types);
    }

    public function versions(string $type, int $page = 1, string $search = ''): array
    {
        $body = $this->http->get('mcjars', '/api/v3/builds/types/' . rawurlencode(Input::id($type)) . '/versions', [
            'page' => max(1, $page), 'per_page' => 20, 'search' => mb_substr($search, 0, 100),
        ]);

        return $this->pagination($body, 'versions');
    }

    public function builds(string $type, string $version, int $page = 1, string $search = ''): array
    {
        $body = $this->http->get('mcjars', '/api/v3/builds/types/' . rawurlencode(Input::id($type))
            . '/versions/' . rawurlencode(Input::id($version)), ['page' => max(1, $page), 'per_page' => 20, 'search' => mb_substr($search, 0, 100)]);

        return $this->pagination($body, 'builds');
    }

    public function exact(string $type, string $version, string $id): array
    {
        if (in_array(strtolower($id), ['latest', 'latest-snapshot'], true) || in_array(strtolower($version), ['latest', 'latest-snapshot'], true)) {
            throw new Failure('exact_selection_required');
        }
        $body = $this->http->get('mcjars', '/api/v3/builds/' . rawurlencode(Input::id($id)));
        $build = $body['build'] ?? [];
        $meta = $body['version'] ?? [];
        $actualVersion = $build['version_id'] ?? $build['project_version_id'] ?? null;
        if (($build['uuid'] ?? null) !== $id || ($build['type'] ?? null) !== $type || $actualVersion !== $version
            || !is_int($meta['java'] ?? null)) {
            throw new Failure('exact_release_unavailable');
        }
        $recipe = Input::list($build, 'installation', 50);
        $shape = [];
        foreach ($recipe as $stage) {
            if (!is_array($stage) || !array_is_list($stage) || count($stage) > 50) {
                throw new Failure('provider_schema_changed');
            }
            foreach ($stage as $step) {
                $shape[] = match ($step['type'] ?? null) {
                    'download', 'unzip', 'remove' => $step['type'],
                    default => 'unknown',
                };
            }
        }

        return [
            'provider' => 'mcjars', 'project' => $type, 'id' => $id,
            'project_title' => (['NEOFORGE' => 'NeoForge', 'BUNGEECORD' => 'BungeeCord'][$type] ?? ucwords(strtolower(str_replace('_', ' ', $type)))).' '.$version,
            'title' => Input::text($build['name'] ?? $id, 200), 'version' => $version,
            'build_version' => ltrim(Input::text($build['project_version_id'] ?? $build['name'] ?? '', 128), '#'),
            'channel' => !empty($build['experimental']) || ($meta['type'] ?? '') !== 'RELEASE' ? 'experimental' : 'release',
            'java' => $meta['java'], 'files' => [], 'dependencies' => [],
            'recipe_shape' => $shape, 'recipe_digest' => \IByte\MinecraftManager\Operations\PlanBuilder::digest($recipe),
            'environment' => 'server', 'compatibility' => 'unknown',
            'reason' => in_array('unknown', $shape, true) ? 'unsupported_recipe' : 'preflight_required',
        ];
    }

    private function pagination(array $body, string $key): array
    {
        $data = $body[$key] ?? null;
        if (!is_array($data) || !is_int($data['total'] ?? null) || !is_int($data['page'] ?? null)) {
            throw new Failure('provider_schema_changed');
        }

        return ['items' => Input::list($data, 'data', 200), 'total' => $data['total'], 'page' => $data['page']];
    }

    public function recipe(string $type, string $version, string $id): array
    {
        $exact = $this->exact($type, $version, $id);
        $body = $this->http->get('mcjars', '/api/v3/builds/' . rawurlencode(Input::id($id)));
        $recipe = $body['build']['installation'] ?? [];
        if (!hash_equals($exact['recipe_digest'], \IByte\MinecraftManager\Operations\PlanBuilder::digest($recipe))) { throw new Failure('provider_release_changed'); }
        return $recipe;
    }
}
