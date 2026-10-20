<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

/** Directional provider platform compatibility, not a promise about arbitrary plugins. */
final class Compatibility
{
    /** Keep provider newest-first order within each channel. */
    public static function orderReleases(array $releases): array
    {
        $rank=['release'=>0,'beta'=>1,'alpha'=>2];
        usort($releases,static fn($a,$b)=>($rank[$a['channel']??'']??3)<=>($rank[$b['channel']??'']??3));
        return $releases;
    }

    public static function platforms(string $target, string $type = 'plugin'): array
    {
        $target = strtolower($target);
        if ($type !== 'plugin') { return $target === '' ? [] : [$target]; }
        return match ($target) {
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'paper' => ['paper', 'spigot', 'bukkit'],
            'spigot' => ['spigot', 'bukkit'],
            'craftbukkit', 'bukkit' => ['bukkit'],
            'waterfall' => ['waterfall', 'bungeecord'],
            // Folia requires explicit support; proxies and mod loaders are separate.
            default => $target === '' ? [] : [$target],
        };
    }

    public static function accepts(string $target, array $advertised, string $type): bool
    {
        return (bool) array_intersect(self::platforms($target, $type), array_map('strtolower', $advertised));
    }

    public static function releaseMatches(array $release, array $context): bool
    {
        $type = $context['type'] ?? 'mod';
        $loaders = $release['loaders'] ?? [];
        // CurseForge Bukkit projects lack a dedicated plugin-platform enum.
        if (($release['provider'] ?? '') === 'curseforge' && $type === 'plugin' && !$loaders) { $loaders = ['bukkit']; }
        if (($release['provider'] ?? '') === 'curseforge' && $type === 'mod' && !$loaders) { return true; }
        return self::accepts($context['loader'] ?? '', $loaders, $type);
    }
}
