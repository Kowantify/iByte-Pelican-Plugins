<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Compatibility;

final class PlanBuilder
{
    public function build(array $release, array $context): array
    {
        $blockers = [];
        if (($release['environment'] ?? '') === 'unsupported') {
            throw new Failure('client_only');
        }
        if (!in_array($release['environment'] ?? '', ['server', 'required', 'optional'], true)) {
            $blockers[] = 'environment_unknown';
        }
        if (($release['channel'] ?? '') !== 'release' && !($context['experimental'] ?? false)) {
            throw new Failure('experimental_disabled');
        }
        if (($context['kind'] ?? '') !== 'software') {
            if (!in_array($context['type'] ?? '', $release['project_types'] ?? [$release['project_type'] ?? ''], true)) {
                throw new Failure('content_type_mismatch');
            }
            if (empty($context['version']) || !in_array($context['version'], $release['game_versions'] ?? [], true)) {
                if (($context['kind'] ?? '') !== 'pack') { $blockers[] = 'game_version_unknown_or_incompatible'; }
            }
            if (($context['kind'] ?? '') !== 'pack' && !Compatibility::releaseMatches($release, $context)) {
                $blockers[] = 'loader_unknown_or_incompatible';
            }
        }
        $warnings = array_values(array_intersect($blockers, ['environment_unknown']));
        if (($release['source_resolution']??null)==='curseforge-cdn') { $warnings[]='curseforge_cdn_fallback'; }
        $blockers = array_values(array_diff($blockers, $warnings));
        foreach ($release['files'] ?? [] as $file) {
            if (!is_int($file['size'] ?? null) || $file['size'] <= 0 || empty($file['hashes']['sha512']) && empty($file['hashes']['sha256']) && empty($file['hashes']['sha1'])) {
                $blockers[] = 'strong_integrity_required';
            }
        }
        if (($context['kind'] ?? '') === 'software') {
            if (in_array('unknown', $release['recipe_shape'] ?? ['unknown'], true)) { $blockers[] = 'unsupported_recipe'; }
        }
        if (($context['kind'] ?? '') === 'package') {
            $installed = $context['runtime_context'] ?? [];
            if (($installed['version'] ?? 'unknown') !== 'unknown' && (($installed['content_type'] ?? '') !== 'proxy' && $installed['version'] !== ($context['version'] ?? '')
                || !empty($installed['loader']) && !Compatibility::accepts($installed['loader'], [$context['loader'] ?? ''], $context['type'])
                || !in_array($installed['content_type'] ?? 'none', [$context['type'], ...($context['type'] === 'plugin' ? ['proxy'] : [])], true)
                || ($installed['content_type'] ?? 'none') === 'none')) { $blockers[] = 'installed_runtime_mismatch'; }
        }

        return [
            'release' => $release, 'blockers' => array_values(array_unique($blockers)), 'warnings' => $warnings,
            'current' => $context['current'] ?? [],
            'proposed' => ['version' => ($context['kind'] ?? '') === 'pack' ? 'Minecraft and exact loader from the pack manifest' : $release['version'], 'image' => 'Approved Java image selected during preflight',
                'startup' => 'Java launcher for runtime installs; unchanged for individual packages'],
            'preserve' => ['worlds', 'configs', 'player lists', 'allocations', 'resource limits', 'native backups'],
            'changes' => [], 'executable' => $blockers === [],
        ];
    }

    public static function digest(array $plan): string
    {
        return hash('sha256', json_encode(self::canonical($plan), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(array $value): array
    {
        // JSON object member order has no meaning. Lists (including ordered
        // recipe stages) retain their order and actual changes remain detectable.
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$item) { if (is_array($item)) { $item = self::canonical($item); } }
        return $value;
    }
}
