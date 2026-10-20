<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Modpacks\Mrpack;
use IByte\MinecraftManager\Support\Compatibility;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;

final class InstallPreparer
{
    private array $files = [];

    private array $projects = [];

    private array $visiting = [];

    private array $incompatible = [];

    private array $skipped = [];

    private array $sourceResolutions = [];

    private int $downloads = 0;

    private int $bytes = 0;

    private \Closure $progress;

    private DirectPreparation $remote;

    private \Closure $runtimeReady;

    public function __construct(private readonly Catalog $catalog,
        private readonly McJars $software, private readonly JsonTransport $http) {}

    public function prepare(array $release, array $selection, DirectPreparation $remote, callable $progress, callable $runtimeReady): array
    {
        $this->files = $this->projects = $this->visiting = $this->incompatible = [];
        $this->downloads = $this->bytes = 0;
        $this->skipped = [];
        $this->sourceResolutions = [];
        $this->progress = \Closure::fromCallable($progress);
        $this->remote = $remote;
        $this->runtimeReady = \Closure::fromCallable($runtimeReady);
        $runtime = null;
        if ($selection['kind'] === 'software') {
            $runtime = $this->runtime($release);
        } elseif ($selection['kind'] === 'package') {
            if (isset($selection['items'])) {
                foreach ($release['items'] as $item) {
                    $this->package($item['release'], $item['selection']);
                }
            } else {
                $this->package($release, $selection);
            }
        } else {
            $runtime = $this->pack($release);
        }
        if ($this->files === [] || count($this->files) > 10000) {
            throw new Failure('artifact_ambiguous');
        }
        foreach ($this->incompatible as $dependency) {
            if (!empty($dependency['project_id']) && isset($this->projects['modrinth:'.$dependency['project_id']])
                || !empty($dependency['version_id']) && in_array($dependency['version_id'], $this->projects, true)) {
                throw new Failure('dependency_incompatible');
            }
        }
        $this->files = $remote->complete($this->files);

        return ['files' => $this->files, 'runtime' => $runtime, 'skipped' => $this->skipped, 'source_resolutions' => $this->sourceResolutions];
    }

    private function fetch(array $artifact): string
    {
        if (++$this->downloads > 2000) {
            throw new Failure('dependency_limit');
        }
        if (($artifact['source_resolution'] ?? null) === 'curseforge-cdn') {
            $this->sourceResolutions[] = $artifact['name'];
        }
        try {
            $result = $this->remote->fetch($artifact);
        } catch (Failure $failure) {
            throw new Failure($failure->reason, ['file' => $artifact['name'] ?? basename(parse_url($artifact['url'], PHP_URL_PATH)), ...$failure->context]);
        }
        $this->bytes += $result['size'];
        if ($this->bytes > 2147483648) {
            throw new Failure('pack_size_limit');
        }

        return $result['reference'];
    }

    private function add(string $path, string $reference, array $owner): void
    {
        Input::path($path);
        foreach ($this->files as $existing => $info) {
            if (strtolower($existing) === strtolower($path) && ($info['remote'] ?? null) !== $reference) {
                throw new Failure('filename_collision');
            }
        }
        $this->files[$path] = [...$this->remote->describe($reference), 'owner' => $owner];
    }

    private function artifact(string $path, array $artifact, array $owner): void
    {
        Input::path($path);
        if (!isset($artifact['size']) || $artifact['size'] < 1 || $artifact['size'] > 536870912) {
            throw new Failure('artifact_size_limit');
        }
        foreach ($this->files as $existing => $info) {
            if (strtolower($existing) !== strtolower($path)) {
                continue;
            }
            if (($info['artifact'] ?? null) === $artifact) {
                return;
            }
            throw new Failure('filename_collision');
        }
        if (($artifact['source_resolution'] ?? '') === 'curseforge-cdn') {
            $this->sourceResolutions[] = $artifact['name'];
        }
        $this->files[$path] = ['artifact' => $artifact, 'size' => $artifact['size'], 'owner' => $owner];
    }

    private function package(array $release, array $context, int $depth = 0): void
    {
        $key = $release['provider'].':'.$release['project'];
        if (isset($this->visiting[$key])) {
            throw new Failure('dependency_cycle');
        }
        if (isset($this->projects[$key])) {
            if ($this->projects[$key] !== $release['id']) {
                throw new Failure('dependency_conflict');
            }

            return;
        }
        if ($depth > 24 || count($this->projects) > 200) {
            throw new Failure('dependency_limit');
        }
        if (($release['environment'] ?? '') === 'unsupported') {
            throw new Failure('client_only');
        }
        if (empty($context['version']) || !in_array($context['version'], $release['game_versions'] ?? [], true)) {
            throw new Failure('game_version_unknown_or_incompatible');
        }
        if (!Compatibility::releaseMatches($release, $context)) {
            throw new Failure('loader_unknown_or_incompatible');
        }
        if (!in_array($context['type'], $release['project_types'] ?? [$release['project_type'] ?? ''], true)) {
            throw new Failure('content_type_mismatch');
        }
        $this->visiting[$key] = true;
        foreach ($release['dependencies'] ?? [] as $dependency) {
            if (($dependency['dependency_type'] ?? '') === 'incompatible') {
                $this->incompatible[] = $dependency;
            }
        }
        $adapter = $this->catalog->provider($release['provider']);
        foreach ($release['dependencies'] ?? [] as $dependency) {
            $required = $release['provider'] === 'modrinth' ? ($dependency['dependency_type'] ?? '') === 'required' : ($dependency['relationType'] ?? 0) === 3;
            if (!$required) {
                continue;
            }
            $child = $adapter->dependency($dependency, $this->catalog->context($context));
            $this->package($child, $context, $depth + 1);
        }
        $artifact = $adapter->artifact($release['project'], $release['id']);
        if (!str_ends_with(strtolower($artifact['name']), '.jar')) {
            throw new Failure('invalid_pack_file');
        }
        $expected = collect($release['files'])->firstWhere('name', $artifact['name']);
        if (!$expected || $expected['size'] !== $artifact['size'] || $expected['hashes'] != $artifact['hashes']) {
            throw new Failure('provider_release_changed');
        }
        $directory = $context['type'] === 'mod' ? 'mods' : 'plugins';
        $this->artifact($directory.'/'.$artifact['name'], $artifact, ['provider' => $release['provider'], 'project' => $release['project'], 'release' => $release['id'], 'kind' => 'package']);
        unset($this->visiting[$key]);
        $this->projects[$key] = $release['id'];
    }

    private function runtime(array $release): array
    {
        ($this->runtimeReady)?->__invoke(['software' => $release['project'], 'version' => $release['version'], 'java' => $release['java'], 'launcher' => 'server.jar', 'build_version' => $release['build_version'] ?? '']);
        $recipe = $this->software->recipe($release['project'], $release['version'], $release['id']);
        if (!hash_equals($release['recipe_digest'], PlanBuilder::digest($recipe))) {
            throw new Failure('provider_release_changed');
        }
        $outputs = [];
        foreach ($recipe as $stage) {
            foreach ($stage as $step) {
                switch ($step['type'] ?? '') {
                    case 'download':
                        $path = Input::path($step['file']);
                        $outputs[$path] = $this->fetch(['url' => $step['url'], 'size' => $step['size']]);
                        break;
                    case 'unzip':
                        $archive = $outputs[Input::path($step['file'])] ?? throw new Failure('unsupported_recipe');
                        $prefix = ($step['location'] ?? '.') === '.' ? '' : Input::path($step['location']).'/';
                        $expanded = $this->remote->extract($archive);
                        foreach ($expanded as $path => $file) {
                            $outputs[$prefix.$path] = $file;
                        }
                        break;
                    case 'remove':
                        $path = Input::path($step['location']);
                        // Remove temporary recipe inputs, never blanket-delete live libraries.
                        unset($outputs[$path]);
                        break;
                    default: throw new Failure('unsupported_recipe');
                }
            }
        }
        $launcher = isset($outputs['server.jar']) ? 'server.jar' : null;
        foreach (['fabric-server-launch.jar', 'quilt-server-launch.jar'] as $candidate) {
            if (!$launcher && isset($outputs[$candidate])) {
                $launcher = $candidate;
            }
        }
        if (!$launcher) {
            throw new Failure('runtime_launcher_missing');
        }
        // A prepared Fabric/Quilt launcher keeps its relative library/property paths
        // when renamed at the root. Never rename a library or installer into a JAR.
        if ($launcher !== 'server.jar') {
            $outputs['server.jar'] = $outputs[$launcher];
            unset($outputs[$launcher]);
            $launcher = 'server.jar';
        }
        foreach ($outputs as $path => $file) {
            if (str_ends_with(strtolower($path), '.sh') || str_ends_with(strtolower($path), '.bat') || str_ends_with(strtolower($path), '.ps1')) {
                continue;
            }
            if ($path !== $launcher && !in_array(explode('/', $path)[0], ['libraries', 'versions'], true) && !in_array($path, ['minecraft.jar', 'server.properties.fabric', 'fabric-server-launcher.properties', 'quilt-server-launcher.properties'], true)) {
                throw new Failure('unsupported_runtime_file');
            }
            $this->add($path, $file, ['provider' => 'mcjars', 'project' => $release['project'], 'release' => $release['id'], 'kind' => 'software']);
        }

        return ['software' => $release['project'], 'version' => $release['version'], 'java' => $release['java'], 'launcher' => $launcher,
            'loader' => strtolower($release['project']), 'build' => $release['id'], 'build_version' => $release['build_version'] ?? ''];
    }

    private function exactRuntime(array $dependencies): array
    {
        $version = Input::id($dependencies['minecraft'] ?? '');
        $type = 'VANILLA';
        $loader = null;
        foreach (['fabric-loader' => 'FABRIC', 'quilt-loader' => 'QUILT', 'forge' => 'FORGE', 'neoforge' => 'NEOFORGE'] as $key => $family) {
            if (isset($dependencies[$key])) {
                if ($loader !== null) {
                    throw new Failure('pack_runtime_unknown');
                } $type = $family;
                $loader = Input::id($dependencies[$key]);
            }
        }
        for ($page = 1; $page <= 100; $page++) {
            $body = $this->http->get('mcjars', '/api/v3/builds/types/'.$type.'/versions/'.rawurlencode($version), ['page' => $page, 'per_page' => 200, ...($loader !== null ? ['search' => $loader] : [])]);
            $builds = $body['builds']['data'] ?? throw new Failure('provider_schema_changed');
            foreach ($builds as $build) {
                if (($loader === null && !($build['experimental'] ?? false)) || ($build['project_version_id'] ?? $build['name'] ?? '') === $loader) {
                    return $this->software->exact($type, $version, Input::id($build['uuid']));
                }
            }
            if ($page * 200 >= ($body['builds']['total'] ?? 0)) {
                break;
            }
        }
        throw new Failure('exact_release_unavailable');
    }

    private function packPath(string $path): bool
    {
        Input::path($path);
        // Packs can contain world templates inside configuration (e.g. yosbr).
        // Only content roots are accepted; live root worlds remain outside them.
        $allowed = in_array(explode('/', $path)[0], ['mods', 'plugins', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'patchouli_books', 'global_packs', 'datapacks', 'resourcepacks', 'shaderpacks'], true)
            || $path === 'server-icon.png';
        if (!$allowed && in_array(basename($path), ['level.dat', 'level.dat_old', 'session.lock'], true)) {
            throw new Failure('world_file_refused', ['file' => $path]);
        }

        return $allowed;
    }

    private function pack(array $release): array
    {
        $adapter = $this->catalog->provider($release['provider']);
        $artifact = $adapter->artifact($release['project'], $release['id']);
        $expected = collect($release['files'])->firstWhere('name', $artifact['name']);
        if (!$expected || $expected['size'] !== $artifact['size'] || $expected['hashes'] != $artifact['hashes']) {
            throw new Failure('provider_release_changed');
        }
        $archive = $this->fetch($artifact);
        $contents = $this->remote->extract($archive);
        $owner = ['provider' => $release['provider'], 'project' => $release['project'], 'release' => $release['id'], 'kind' => 'pack'];
        if ($release['provider'] === 'modrinth') {
            $manifestFile = $contents['modrinth.index.json'] ?? throw new Failure('invalid_pack_manifest');
            $manifest = $this->remote->manifest($manifestFile);
            $parsed = (new Mrpack())->parse($manifest);
            $sourceFiles = array_column($manifest['files'], null, 'path');
            foreach ($parsed['files'] as $file) {
                if ($file['optional']) {
                    continue;
                }
                if (!$this->packPath($file['path'])) {
                    throw new Failure('invalid_pack_file', ['file' => $file['path']]);
                }
                $meta = $sourceFiles[$file['path']];
                $this->artifact($file['path'], ['url' => $meta['downloads'][0], 'name' => basename($file['path']), 'size' => $file['size'], 'hashes' => $meta['hashes']], $owner);
            }
            foreach ((new Mrpack())->overrides(array_keys($contents)) as $path => $source) {
                if ($this->packPath($path)) {
                    $this->addOverride($path, $contents[$source], $owner);
                }
            }
            ($this->progress)(['phase' => 'runtime', 'files' => 0]);
            $runtime = $this->exactRuntime($parsed['dependencies']);
        } else {
            $manifestFile = $contents['manifest.json'] ?? throw new Failure('invalid_pack_manifest');
            $manifest = $this->remote->manifest($manifestFile);
            $deps = ['minecraft' => Input::id($manifest['minecraft']['version'] ?? '')];
            $loaders = $manifest['minecraft']['modLoaders'] ?? [];
            if (!is_array($loaders) || count($loaders) > 10 || ($loaders && count(array_filter($loaders, static fn ($loader) => is_array($loader) && ($loader['primary'] ?? false) === true)) !== 1)) {
                throw new Failure('pack_runtime_unknown');
            }
            foreach ($loaders as $loader) {
                if (empty($loader['primary'])) {
                    continue;
                }
                [$family, $loaderVersion] = array_pad(explode('-', $loader['id'] ?? '', 2), 2, '');
                $key = ['forge' => 'forge', 'neoforge' => 'neoforge', 'fabric' => 'fabric-loader', 'quilt' => 'quilt-loader'][$family] ?? throw new Failure('pack_runtime_unknown');
                $deps[$key] = Input::id($loaderVersion);
            }
            if (!empty($release['server_pack_id'])) {
                $serverArchive = $this->fetch($adapter->artifact($release['project'], $release['id'], true));
                $serverFiles = $this->remote->extract($serverArchive);
                foreach ($serverFiles as $path => $local) {
                    if ($this->packPath($path)) {
                        $this->addOverride($path, $local, $owner);
                    }
                }
                if ($this->files === []) {
                    throw new Failure('unsupported_server_pack_layout');
                }
            } else {
                $manifestFiles = Input::list($manifest, 'files', 2000);
                ($this->progress)(['phase' => 'resolve', 'files' => 0, 'total' => count($manifestFiles)]);
                $resolved = 0;
                $guard = function () use (&$resolved, $manifestFiles): void {
                    ($this->progress)(['phase' => 'resolve', 'files' => $resolved, 'total' => count($manifestFiles), 'file' => '']);
                };
                foreach ($adapter->manifestReleases($manifestFiles, $guard) as $meta) {
                    ($this->progress)(['phase' => 'resolve', 'files' => ++$resolved, 'total' => count($manifestFiles), 'file' => $meta['project']]);
                    $project = $meta['project'];
                    $id = $meta['id'];
                    if (isset($meta['skip'])) {
                        $this->skipped[] = $meta;

                        continue;
                    }
                    $artifact = $meta['artifact'] ?? $adapter->artifact($project, $id);
                    if (!str_ends_with(strtolower($artifact['name']), '.jar')) {
                        throw new Failure('invalid_pack_file', ['file' => $artifact['name']]);
                    }
                    $this->artifact('mods/'.$artifact['name'], $artifact, $owner);
                }
                ($this->progress)?->__invoke(['phase' => 'resolve', 'files' => count($manifestFiles), 'total' => count($manifestFiles), 'file' => '']);
                $prefix = Input::path($manifest['overrides'] ?? 'overrides').'/';
                foreach ($contents as $source => $local) {
                    if (str_starts_with($source, $prefix)) {
                        $path = substr($source, strlen($prefix));
                        if ($this->packPath($path)) {
                            $this->addOverride($path, $local, $owner);
                        }
                    }
                }
            }
            ($this->progress)(['phase' => 'runtime', 'files' => 0]);
            $runtime = $this->exactRuntime($deps);
        }

        return $this->runtime($runtime);
    }

    private function addOverride(string $path, string $local, array $owner): void
    {
        // Server overrides intentionally replace the common layer in the prepared tree.
        unset($this->files[$path]);
        $this->add($path, $local, $owner);
    }
}
