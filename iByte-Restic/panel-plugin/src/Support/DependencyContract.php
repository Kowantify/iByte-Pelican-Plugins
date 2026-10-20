<?php

namespace IByte\Restic\Support;

use Composer\InstalledVersions;
use Throwable;

final class DependencyContract
{
    public static function inspect(array $expected): array
    {
        if ($expected === []) {
            return [['package' => '[baseline]', 'reason' => 'missing tested runtime package inventory']];
        }
        try {
            $lock = json_decode(file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($lock['packages'] ?? null)) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            return [['package' => '[lockfile]', 'reason' => 'runtime packages cannot be read']];
        }
        $installed = [];
        foreach ($expected as $name => $pin) {
            try {
                if (InstalledVersions::isInstalled($name)) {
                    $installed[$name] = ['version' => InstalledVersions::getPrettyVersion($name), 'reference' => InstalledVersions::getReference($name)];
                }
            } catch (Throwable) {
                // A package present only as an alias/replacement is not proof
                // that the tested package itself was installed.
            }
        }

        return self::compare($expected, $lock['packages'], $installed);
    }

    // Pure comparison also exercised with isolated package fixtures. Extra
    // plugin packages, dev packages and lock metadata are not source contracts.
    public static function compare(array $expected, array $packages, array $installed): array
    {
        $locked = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || isset($locked[$package['name']])) {
                return [['package' => '[lockfile]', 'reason' => 'invalid or duplicate runtime package entry']];
            }
            $locked[$package['name']] = $package;
        }
        $mismatches = [];
        foreach ($expected as $name => $pin) {
            $package = $locked[$name] ?? null;
            $actual = $installed[$name] ?? null;
            $lockIdentity = $package ? ['version' => $package['version'] ?? null, 'source_reference' => $package['source']['reference'] ?? null,
                'dist_reference' => $package['dist']['reference'] ?? null, 'source_url' => $package['source']['url'] ?? null] : null;
            $references = array_unique([$pin['dist_reference'], $pin['source_reference']]);
            $lockMatches = $lockIdentity !== null;
            foreach ($pin as $field => $value) {
                $lockMatches = $lockMatches && array_key_exists($field, $lockIdentity ?? []) && $lockIdentity[$field] === $value;
            }
            $installedMatches = $actual && ltrim($actual['version'] ?? '', 'v') === ltrim($pin['version'], 'v') && in_array($actual['reference'] ?? null, $references, true);
            if (! $lockMatches || ! $installedMatches) {
                $mismatches[] = ['package' => $name, 'reason' => ! $package ? 'missing locked runtime package' : (! $actual ? 'missing installed runtime package' : 'tested runtime package identity differs'),
                    'expected_version' => $pin['version'], 'locked_version' => self::version($lockIdentity['version'] ?? null), 'installed_version' => self::version($actual['version'] ?? null),
                    'locked_identity_matches' => $lockMatches, 'installed_identity_matches' => (bool) $installedMatches];
            }
        }

        return $mismatches;
    }

    private static function version(mixed $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return is_string($version) && strlen($version) <= 100 && preg_match('/^(?:v?[0-9][0-9A-Za-z.+_-]*|dev-[0-9A-Za-z._-]+)$/D', $version) ? $version : '[invalid version]';
    }
}
