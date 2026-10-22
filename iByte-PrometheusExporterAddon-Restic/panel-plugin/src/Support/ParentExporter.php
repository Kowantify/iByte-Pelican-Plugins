<?php

namespace IByte\PrometheusExporterAddonRestic\Support;

use App\Enums\PluginStatus;
use App\Models\Plugin;
use IByte\PrometheusExporter\Support\Settings as ExporterSettings;

/**
 * Single dependency checkpoint. The add-on is only meaningful while the base
 * exporter plugin is installed and enabled; every entry point (route and
 * scheduled collection) asks this class first.
 */
final class ParentExporter
{
    public const ID = 'ibyte-prometheus-exporter';
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
        // Read through the base exporter's public API. Its storage format and
        // location are private implementation details and can change in an
        // exporter update without requiring an add-on release.
        if (! class_exists(ExporterSettings::class) || ! is_callable([ExporterSettings::class, 'read'])) {
            return ['token_hash' => '', 'panel_id' => ''];
        }

        $data = ExporterSettings::read();

        return [
            'token_hash' => is_array($data) ? (string) ($data['token_hash'] ?? '') : '',
            'panel_id' => is_array($data) ? (string) ($data['panel_id'] ?? '') : '',
        ];
    }
}
