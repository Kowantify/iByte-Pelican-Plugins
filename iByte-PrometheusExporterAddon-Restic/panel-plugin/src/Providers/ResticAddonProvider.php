<?php

namespace IByte\PrometheusExporterAddonRestic\Providers;

use IByte\PrometheusExporter\Events\MetricsRendered;
use IByte\PrometheusExporterAddonRestic\Console\CollectRestic;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ResticAddonProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([CollectRestic::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Runs right after the base collector's five-second schedule.
            $schedule->command('ibyte:prometheus-collect-restic')
                ->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();
        });

        // Append the addon's cached families onto the base exporter's scrape
        // response so the operator's existing Prometheus job picks them up
        // with no new scrape config. Every appended line is shape-validated
        // by the base endpoint (MetricsRendered::append).

        \Illuminate\Support\Facades\Event::listen(MetricsRendered::class, function (MetricsRendered $event): void {
            $snapshot = \Illuminate\Support\Facades\Cache::get('ibyte-prometheus-addon-restic:latest', []);
            $panelId = self::escape((string) $event->panelId);
            foreach ((array) ($snapshot['servers'] ?? []) as $server) {
                if (! is_array($server)) {
                    continue;
                }
                $base = '{panel_id="'.$panelId.'",node_id="'.(int) ($server['node_id'] ?? 0)
                    .'",server_uuid="'.self::escape((string) ($server['server_uuid'] ?? '')).'"}';
                $event->append('ibyte_pelican_restic_repo_info'.$base.',server_name="'.self::escape((string) ($server['server_name'] ?? ''))
                    .'",owner="'.self::escape((string) ($server['owner'] ?? ''))
                    .'",owner_id="'.(int) ($server['owner_id'] ?? 0)
                    .'",host_id="'.(int) ($server['host_id'] ?? 0)
                    .'",backend="'.self::escape((string) ($server['backend'] ?? ''))
                    .'",profile="'.self::escape((string) ($server['profile'] ?? ''))
                    .'",generation="'.self::escape((string) ($server['generation'] ?? '')).'" 1');
                if (isset($server['file_bytes']) && is_int($server['file_bytes'])) {
                    $event->append('ibyte_pelican_restic_repo_file_bytes'.$base.' '.$server['file_bytes']);
                }
                $event->append('ibyte_pelican_restic_repo_files_measured'.$base.' '.(int) (($server['file_measured'] ?? 0) === 1));
                foreach (['blobs_compressed_bytes', 'blobs_uncompressed_bytes', 'logical_bytes'] as $key) {
                    if (isset($server[$key]) && is_int($server[$key])) {
                        $event->append('ibyte_pelican_restic_repo_'.$key.$base.' '.$server[$key]);
                    }
                }
                if (isset($server['snapshots_count']) && is_int($server['snapshots_count'])) {
                    $event->append('ibyte_pelican_restic_repo_snapshots'.$base.' '.$server['snapshots_count']);
                }
                foreach (['compression_ratio', 'compression_space_saving'] as $key) {
                    if (isset($server[$key]) && is_numeric($server[$key]) && is_finite((float) $server[$key])) {
                        $event->append('ibyte_pelican_restic_repo_'.$key.$base.' '.(float) $server[$key]);
                    }
                }
                if (isset($server['measured_at']) && is_int($server['measured_at']) && $server['measured_at'] > 0) {
                    $event->append('ibyte_pelican_restic_repo_measured_timestamp_seconds'.$base.' '.$server['measured_at']);
                }
            }
            // HELP/TYPE metadata and the self-health gauge, appended last.
            $event->append('# HELP ibyte_pelican_restic_addon_success Whether the latest Restic metrics collection succeeded.');
            $event->append('# TYPE ibyte_pelican_restic_addon_success gauge');
            $event->append('ibyte_pelican_restic_addon_success{panel_id="'.$panelId.'"} '
                .((($snapshot['success'] ?? false) === true) ? 1 : 0));
        });
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, "\\\"\n");
    }
}