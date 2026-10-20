<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

final class Input
{
    public static function image(mixed $url): ?string
    {
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) { return null; }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && $parts['port'] !== 443
            || !in_array(strtolower($parts['host'] ?? ''), ['cdn.modrinth.com', 'cdn-raw.modrinth.com', 'media.forgecdn.net', 'mediafilez.forgecdn.net', 'edge.forgecdn.net'], true)) { return null; }
        return $url;
    }

    public static function id(mixed $id): string
    {
        if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+ -]{0,127}\z/D', (string) $id)
            || in_array((string) $id, ['.', '..'], true)) {
            throw new Failure('invalid_identifier');
        }

        return (string) $id;
    }

    public static function path(mixed $path, int $maxLength = 240, int $maxDepth = 16): string
    {
        if (!is_string($path) || $path === '' || strlen($path) > $maxLength || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f:<>"|?*]/', $path) || str_starts_with($path, '/') || str_contains($path, '//')) {
            throw new Failure('unsafe_path');
        }
        $parts = explode('/', $path);
        if (count($parts) > $maxDepth) {
            throw new Failure('unsafe_path');
        }
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                throw new Failure('unsafe_path');
            }
        }

        return $path;
    }

    public static function nodePath(mixed $path): string
    {
        // User/provider paths keep their original bounds. Generated operation
        // prefixes add directories and bytes without making the content unsafe.
        $internal = is_string($path) && (preg_match('~\A\.download-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-[1-9][0-9]*\.(?:part|zip)(?:/|\z)~D', $path)
            || preg_match('~\A\.minecraft-manager/(?:staging|backups)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/~D', $path)
            || preg_match('~(?:\A|/)\.download-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-[1-9][0-9]*\.(?:part|zip)\z~D', $path));
        return self::path($path, $internal ? 512 : 240, $internal ? 32 : 16);
    }

    public static function filename(mixed $name): string
    {
        $path = self::path($name);
        if (str_contains($path, '/')) {
            throw new Failure('unsafe_filename');
        }

        return $path;
    }

    public static function text(mixed $value, int $limit = 2000): string
    {
        return mb_substr(strip_tags(is_string($value) ? $value : ''), 0, $limit);
    }

    public static function list(array $value, string $key, int $limit = 1000): array
    {
        $list = $value[$key] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > $limit) {
            throw new Failure('provider_schema_changed');
        }

        return $list;
    }
}
