<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

final class UrlPolicy
{
    public const ARTIFACT_HOSTS = [
        'cdn.modrinth.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net', 'media.forgecdn.net',
        'piston-data.mojang.com', 'launcher.mojang.com', 'fill-data.papermc.io', 'api.papermc.io',
        'api.purpurmc.org', 'meta.fabricmc.net', 'maven.fabricmc.net', 'maven.minecraftforge.net',
        'maven.neoforged.net', 'files.mcjars.app', 'versions.mcjars.app', 'mcjars.app',
    ];

    public static function host(string $url, array $allowedHosts): string
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new Failure('unsafe_url');
        }
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, $allowedHosts, true) || !preg_match('/\A[a-z0-9.-]+\z/D', $host)) {
            throw new Failure('unapproved_host');
        }

        return $host;
    }

    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        if (!str_contains($ip, ':') && ord(inet_pton($ip)[0]) >= 224) {
            return false;
        }
        // IPv4-mapped, NAT64, 6to4, Teredo and other transition addresses are not used.
        if (str_contains($ip, ':') && !str_starts_with(strtolower(bin2hex(inet_pton($ip))), '2')
            && !str_starts_with(strtolower(bin2hex(inet_pton($ip))), '3')) {
            return false;
        }
        if (str_contains($ip, ':') && preg_match('/\A(?:2001:|2002:)/i', $ip)) {
            return false;
        }

        return true;
    }

    public static function pinnedAddress(array $addresses): string
    {
        if ($addresses === [] || count($addresses) > 32) {
            throw new Failure('dns_failed');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::publicIp($address)) {
                throw new Failure('unsafe_address');
            }
        }

        return $addresses[0];
    }
}
