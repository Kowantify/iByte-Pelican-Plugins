<?php

namespace IByte\PrometheusExporterAddonRestic\Console;

use IByte\PrometheusExporterAddonRestic\Storage\RepositoryMetricsReader;
use IByte\PrometheusExporterAddonRestic\Support\ParentExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Collects the admin-visible Restic storage measurements into one cached
 * snapshot for exposition. Same shape as the base exporter's Collect: one
 * cached blob, generation-checked, re-key rotated by the base plugin.
 */
final class CollectRestic extends Command
{
    protected $signature = 'ibyte:prometheus-collect-restic';
    protected $description = 'Cache iByte Restic repository storage metrics for the Prometheus exporter add-on';

    public function handle(): int
    {
        $lock = Cache::lock('ibyte-prometheus-addon-restic:collector', 120);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            return $this->collect();
        } finally {
            $lock->release();
        }
    }

    private function collect(): int
    {
        $settings = ParentExporter::settings();
        // The add-on is inert without its parent: no base exporter key, or
        // base exporter not installed/enabled, means nothing collects.
        if ($settings['token_hash'] === '' || ! ParentExporter::active()) {
            return self::SUCCESS;
        }

        $servers = [];
        try {
            $snapshot = ['success' => true, 'error' => '', 'collected_at' => time(),
                'servers' => RepositoryMetricsReader::latest()];
        } catch (Throwable $error) {
            // A failing collection leaves the previous snapshot in cache and
            // flags success=0 for the round; missing data is never zeroed.
            $snapshot = ['success' => false, 'error' => class_basename($error),
                'servers' => [], 'collected_at' => time()];
        }

        Cache::put('ibyte-prometheus-addon-restic:latest', $snapshot, 600);

        return self::SUCCESS;
    }
}