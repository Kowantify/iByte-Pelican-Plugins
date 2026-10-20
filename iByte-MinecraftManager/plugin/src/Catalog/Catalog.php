<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Catalog;

use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Cache;

final class Catalog
{
    public function __construct(private readonly JsonTransport $http, private readonly Settings $settings) {}

    public function provider(string $provider): Modrinth|CurseForge
    {
        if (!$this->settings->providerEnabled($provider)) {
            throw new Failure('provider_disabled');
        }

        return match ($provider) {
            'modrinth' => new Modrinth($this->http),
            'curseforge' => new CurseForge($this->http),
            'hangar', 'github', 'ftb', 'atlauncher' => throw new Failure('provider_not_verified'),
            default => throw new Failure('unsupported_provider'),
        };
    }

    public function context(array $input): array
    {
        $type = $input['type'] ?? 'mod';
        if (!in_array($type, ['mod', 'plugin', 'modpack'], true)) {
            throw new Failure('invalid_content_type');
        }
        $experimental = (bool) ($input['experimental'] ?? false);
        $sort = $input['sort'] ?? 'popular';
        if (!in_array($sort, ['popular', 'downloads', 'updated'], true)) { throw new Failure('invalid_selection'); }

        return [
            'type' => $type, 'sort' => $sort, 'query' => Input::text($input['query'] ?? '', 100),
            'page' => min(500, max(1, (int) ($input['page'] ?? 1))),
            'release_page' => min(200, max(1, (int) ($input['release_page'] ?? 1))),
            'version' => empty($input['version']) ? '' : Input::id($input['version']),
            'loader' => empty($input['loader']) ? '' : strtolower(Input::id($input['loader'])), 'experimental' => $experimental,
        ];
    }

    public function search(string $provider, array $input): array
    {
        $adapter = $this->provider($provider);
        $context = $this->context($input);
        // Credential replacement invalidates the key without caching any credential.
        $credentialContext = $provider === 'curseforge' ? hash('sha256', $this->settings->credential($provider) ?? '') : '';
        $key = 'imm.catalog.v9.' . hash('sha256', json_encode([$provider, $context, $credentialContext], JSON_THROW_ON_ERROR));

        return Cache::remember($key, 300, fn () => $adapter->search($context));
    }

    public function releases(string $provider, string $project, array $input, ?bool &$hasMore = null): array
    {
        return $this->provider($provider)->releases(Input::id($project), $this->context($input), $hasMore);
    }

    public function exact(string $provider, string $project, string $release): array
    {
        return $this->provider($provider)->exact(Input::id($project), Input::id($release));
    }

    /** Display metadata only; install actions resolve the exact release afresh. */
    public function latest(string $provider, string $project, array $input): ?array
    {
        $this->provider($provider);
        $context = $this->context([...$input, 'release_page'=>1]);
        $credential = $provider === 'curseforge' ? hash('sha256', $this->settings->credential($provider) ?? '') : '';
        $key = 'imm.latest.v1.'.hash('sha256', json_encode([$provider, Input::id($project), $context, $credential], JSON_THROW_ON_ERROR));
        return Cache::remember($key, 120, fn () => $this->latestRelease($provider, $project, $context));
    }

    public function latestRelease(string $provider, string $project, array $input): ?array
    {
        // CurseForge pages raw files before excluding experimental channels.
        // A short/empty stable page does not mean older releases are unavailable.
        for ($page=1; $page<=200; ++$page) {
            $hasMore = false;
            $releases = $this->releases($provider, $project, [...$input, 'release_page'=>$page], $hasMore);
            if ($releases) { return $releases[0]; }
            if (!$hasMore) { break; }
        }
        return null;
    }
}
