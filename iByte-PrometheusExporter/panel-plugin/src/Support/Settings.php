<?php

namespace IByte\PrometheusExporter\Support;

use Illuminate\Support\Str;

final class Settings
{
    private static function path(): string
    {
        return storage_path('app/ibyte-prometheus-exporter/settings.json');
    }

    public static function read(): array
    {
        $path = self::path();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return array_replace([
            'token_hash' => '', 'generation' => '', 'panel_id' => '',
        ], is_array($data) ? $data : []);
    }

    public static function write(array $settings): void
    {
        // Atomically rewrite settings. panel_id must stay stable so Prometheus labels
        // persist across key rotation; the generation changes only when the token hash
        // does, so cached snapshots stop being served immediately after revocation.
        $previous = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : [];
        $settings['panel_id'] = (string) ($previous['panel_id'] ?? '') ?: (string) Str::uuid();
        if (($previous['token_hash'] ?? null) !== ($settings['token_hash'] ?? null) || empty($settings['generation'])) {
            $settings['generation'] = (string) Str::uuid();
        } else {
            $settings['generation'] = (string) $previous['generation'];
        }
        $directory = dirname(self::path());
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create exporter settings directory');
        }
        $temporary = tempnam($directory, 'settings-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create exporter settings file');
        }
        try {
            chmod($temporary, 0600);
            $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, self::path())) {
                throw new \RuntimeException('Cannot save exporter settings');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
