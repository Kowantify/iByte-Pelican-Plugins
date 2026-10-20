<?php

namespace IByte\PrometheusExporterAddonRestic\Support;

use App\Enums\PluginStatus;
use App\Models\Plugin;

/**
 * Single dependency checkpoint. The add-on is only meaningful while the base
 * exporter plugin is installed and enabled; every entry point (route and
 * scheduled collection) asks this class first.
 */
final class ParentExporter
{
    public const ID = 'ibyte-prometheus-exporter';
    private const SETTINGS = 'ibyte-prometheus-exporter/settings.json';

    /** Base exporter installed AND enabled (Pelican plugin status, as tests use). */
    public static function active(): bool
    {
        $plugin = Plugin::query()->where('id', self::ID)->first();

        return $plugin !== null && $plugin->status === PluginStatus::Enabled;
    }

    /**
     * Reads the base exporter's own settings file (same storage path the base
     * writes) so there is exactly one scrape key and one stable panel_id in
     * the whole deployment. Never a second key, never a second identity.
     *
     * @return array{token_hash: string, panel_id: string}
     */
    public static function settings(): array
    {
        $path = storage_path('app/'.self::SETTINGS);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return [
            'token_hash' => is_array($data) ? (string) ($data['token_hash'] ?? '') : '',
            'panel_id' => is_array($data) ? (string) ($data['panel_id'] ?? '') : '',
        ];
    }
}