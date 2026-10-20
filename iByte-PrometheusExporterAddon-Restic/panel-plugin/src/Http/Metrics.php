<?php

namespace IByte\PrometheusExporterAddonRestic\Http;

use IByte\PrometheusExporterAddonRestic\Support\ParentExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Serves the cached Restic measurements in Prometheus text format.
 * Auth reuses the base exporter's scrape key — one key total, same bearer
 * comparison, same hash_equals discipline, same 401 surface.
 */
final class Metrics
{
    public function __invoke(Request $request)
    {
        $settings = ParentExporter::settings();
        $provided = $request->bearerToken();
        if (! $provided || ! ParentExporter::active() || $settings['token_hash'] === ''
            || ! hash_equals($settings['token_hash'], hash('sha256', $provided))) {
            abort(401);
        }

        $lines = [
            '# HELP ibyte_pelican_restic_addon_success Whether the latest Restic metrics collection succeeded.',
            '# TYPE ibyte_pelican_restic_addon_success gauge',
        ];
        $snapshot = Cache::get('ibyte-prometheus-addon-restic:latest', []);
        $panelId = self::escape((string) ($settings['panel_id'] ?? ''));

        $lines = [
            '# HELP ibyte_pelican_restic_addon_success Whether the latest Restic metrics collection succeeded.',
            '# TYPE ibyte_pelican_restic_addon_success gauge',
            'ibyte_pelican_restic_addon_success{panel_id="'.$panelId.'"} '.((($snapshot['success'] ?? false) === true) ? 1 : 0),
            '# HELP ibyte_pelican_restic_repo_info Restic repository identity; one info series per current repository.',
            '# TYPE ibyte_pelican_restic_repo_info gauge',
            '# HELP ibyte_pelican_restic_repo_file_bytes Total on-disk file lengths in the Restic repository, including metadata and unpruned packs. Unavailable for remote backends; never rendered as zero.',
            '# TYPE ibyte_pelican_restic_repo_file_bytes gauge',
            '# HELP ibyte_pelican_restic_repo_files_measured Whether a repository-file measurement exists for this repository.',
            '# TYPE ibyte_pelican_restic_repo_files_measured gauge',
            '# HELP ibyte_pelican_restic_repo_blobs_compressed_bytes Unique compressed blob bytes referenced by the repository.',
            '# TYPE ibyte_pelican_restic_repo_blobs_compressed_bytes gauge',
            '# HELP ibyte_pelican_restic_repo_blobs_uncompressed_bytes Unique uncompressed blob bytes referenced by the repository.',
            '# TYPE ibyte_pelican_restic_repo_blobs_uncompressed_bytes gauge',
            '# HELP ibyte_pelican_restic_repo_logical_bytes Total logical restore bytes across all snapshots.',
            '# TYPE ibyte_pelican_restic_repo_logical_bytes gauge',
            '# HELP ibyte_pelican_restic_repo_snapshots Snapshots counted in the latest saved measurement.',
            '# TYPE ibyte_pelican_restic_repo_snapshots gauge',
            '# HELP ibyte_pelican_restic_repo_compression_ratio Unique uncompressed / compressed blob bytes.',
            '# TYPE ibyte_pelican_restic_repo_compression_ratio gauge',
            '# HELP ibyte_pelican_restic_repo_compression_space_saving Percent of blob bytes saved by compression.',
            '# TYPE ibyte_pelican_restic_repo_compression_space_saving gauge',
            '# HELP ibyte_pelican_restic_repo_measured_timestamp_seconds Unix time of the latest saved repository measurement.',
            '# TYPE ibyte_pelican_restic_repo_measured_timestamp_seconds gauge',
        ];

        foreach ((array) ($snapshot['servers'] ?? []) as $server) {
            if (! is_array($server)) {
                continue;
            }
            $base = '{panel_id="'.$panelId.'",node_id="'.(int) ($server['node_id'] ?? 0)
                .'",server_uuid="'.self::escape((string) ($server['server_uuid'] ?? '')).'"}';
            $lines[] = 'ibyte_pelican_restic_repo_info'.$base.',server_name="'.self::escape((string) ($server['server_name'] ?? ''))
                .'",owner="'.self::escape((string) ($server['owner'] ?? ''))
                .'",owner_id="'.(int) ($server['owner_id'] ?? 0)
                .'",host_id="'.(int) ($server['host_id'] ?? 0)
                .'",backend="'.self::escape((string) ($server['backend'] ?? ''))
                .'",profile="'.self::escape((string) ($server['profile'] ?? ''))
                .'",generation="'.self::escape((string) ($server['generation'] ?? '')).'" 1';

            if (isset($server['file_bytes']) && is_int($server['file_bytes'])) {
                $lines[] = 'ibyte_pelican_restic_repo_file_bytes'.$base.' '.$server['file_bytes'];
            }
            $lines[] = 'ibyte_pelican_restic_repo_files_measured'.$base.' '.(int) (($server['file_measured'] ?? 0) === 1);
            foreach (['blobs_compressed_bytes', 'blobs_uncompressed_bytes', 'logical_bytes'] as $key) {
                if (isset($server[$key]) && is_int($server[$key])) {
                    $lines[] = 'ibyte_pelican_restic_repo_'.$key.$base.' '.$server[$key];
                }
            }
            if (isset($server['snapshots_count']) && is_int($server['snapshots_count'])) {
                $lines[] = 'ibyte_pelican_restic_repo_snapshots'.$base.' '.$server['snapshots_count'];
            }
            foreach (['compression_ratio', 'compression_space_saving'] as $key) {
                if (isset($server[$key]) && is_numeric($server[$key]) && is_finite((float) $server[$key])) {
                    $lines[] = 'ibyte_pelican_restic_repo_'.$key.$base.' '.(float) $server[$key];
                }
            }
            if (isset($server['measured_at']) && is_int($server['measured_at']) && $server['measured_at'] > 0) {
                $lines[] = 'ibyte_pelican_restic_repo_measured_timestamp_seconds'.$base.' '.$server['measured_at'];
            }
        }

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, "\\\"\n");
    }
}