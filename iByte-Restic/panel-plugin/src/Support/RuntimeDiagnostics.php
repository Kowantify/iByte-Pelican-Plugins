<?php

namespace IByte\Restic\Support;

use App\Models\Plugin;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Integration\BridgeRepository;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Integration\RetainingBackupService;
use ReflectionClass;
use Throwable;

// Local diagnostics only. Never print host configuration, tokens or escrow.
final class RuntimeDiagnostics
{
    public static function bindings(): array
    {
        $results = [];
        foreach ([InitiateBackupService::class => RetainingBackupService::class,
            DaemonBackupRepository::class => BridgeRepository::class,
            ServerDeletionService::class => GuardedServerDeletion::class] as $service => $expected) {
            try {
                $instance = app($service);
                $results[$service] = ['expected' => $expected, 'actual' => $instance::class,
                    'file' => (new ReflectionClass($instance))->getFileName(), 'matches' => $instance instanceof $expected];
            } catch (Throwable $exception) {
                $results[$service] = ['expected' => $expected, 'actual' => null, 'matches' => false, 'resolution_error' => $exception::class];
            }
        }

        return $results;
    }

    public static function snapshot(): array
    {
        $plugin = Plugin::find('ibyte-restic');
        $bindings = self::bindings();
        try {
            $contracts = Compatibility::panelSource();
            $source = $contracts['matches'];
        } catch (Throwable) {
            $source = false;
            $contracts = ['matches' => false, 'error' => 'Source contract inventory could not be read.'];
        }

        return ['pid' => getmypid(), 'php' => PHP_VERSION, 'console' => app()->runningInConsole(),
            'panel_path' => base_path(), 'diagnostic_file' => __FILE__,
            'manifest_sha256' => is_file($manifest = base_path('plugins/ibyte-restic/plugin.json')) ? hash_file('sha256', $manifest) : null,
            'plugin' => $plugin?->only(['id', 'version', 'status', 'load_order']),
            'declared_providers' => $plugin?->getProviders() ?? [],
            'loaded_restic_providers' => array_keys(array_filter(app()->getLoadedProviders(), fn ($loaded, $class) => $loaded && str_starts_with($class, 'IByte\\Restic\\'), ARRAY_FILTER_USE_BOTH)),
            'bindings' => $bindings, 'panel_source_matches' => $source,
            'panel_contract' => $contracts,
            'ready' => $source && ! in_array(false, array_column($bindings, 'matches'), true),
            'note' => 'This describes this process only. A fresh CLI cannot prove what a long-lived queue or PHP-FPM process has loaded. No binding is replaced and no Wings request is made.'];
    }
}
