<?php

namespace IByte\Restic\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class Installation
{
    private const TABLES = [
        'ibyte_restic_operations', 'ibyte_restic_repositories',
        'ibyte_restic_recovery_snapshots', 'ibyte_restic_recovery_audit', 'ibyte_restic_retention', 'ibyte_restic_key_history',
    ];

    public static function missing(): array
    {
        $missing = array_values(array_filter(self::TABLES, fn ($table) => ! Schema::hasTable($table)));
        if (! in_array('ibyte_restic_operations', $missing, true) && ! Schema::hasColumn('ibyte_restic_operations', 'metrics')) {
            $missing[] = 'ibyte_restic_operations.metrics';
        }
        if (! in_array('ibyte_restic_operations', $missing, true) && ! Schema::hasColumn('ibyte_restic_operations', 'alerts_delivered_at')) {
            $missing[] = 'ibyte_restic_operations.alerts_delivered_at';
        }

        return $missing;
    }

    public static function assertReady(): void
    {
        if (self::missing() !== []) {
            throw new HttpException(503, 'Restic installation is incomplete: plugin database tables are missing. An administrator must use Finish installation / Repair tables in Admin > Restic Backups > Administrative. Do not uninstall or delete backups to update.', headers: ['Retry-After' => 0]);
        }
    }

    public static function repair(): void
    {
        // Local additive table repair must remain possible when a node-execution
        // binding is unavailable. Exact Panel source contracts still apply.
        Compatibility::assertPanelSource();
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Finish the current database transaction before repairing plugin tables.');
        }
        $lock = Cache::lock('ibyte-restic:install-repair', 120);
        if (! $lock->get()) {
            throw new RuntimeException('Another Restic installation repair is running.');
        }
        try {
            $path = dirname(__DIR__, 2).'/database/migrations';
            app(Migrator::class)->run($path);
            // Repair a table missing even when its migration is already logged.
            // Only these reviewed plugin-owned, idempotent up() methods run;
            // never rollback, refresh, drop, edit migration history or touch nodes.
            foreach ([
                '2026_10_14_000001_create_ibyte_restic_operations.php',
                '2026_10_15_000001_add_ibyte_restic_storage_metrics.php',
                '2026_10_15_000002_create_ibyte_restic_recovery_inventory.php',
                '2026_10_15_000003_create_ibyte_restic_retention.php',
                '2026_10_15_000004_create_ibyte_restic_key_history.php',
                '2026_10_16_000001_add_ibyte_restic_alert_delivery.php',
            ] as $file) {
                (require $path.'/'.$file)->up();
            }
            self::assertReady();
            Maintenance::dispatch();
        } finally {
            $lock->release();
        }
    }

    public static function action(): Action
    {
        return Action::make('ibyte-restic-repair-installation')->label('Finish installation / Repair tables')->button()
            ->authorize(fn () => user()?->isRootAdmin())->requiresConfirmation()
            ->modalDescription('Applies only this plugin’s additive migrations and recreates missing plugin tables. Keeps existing backups, hosts, node links, credentials and repositories. Missing historical keys cannot be recreated from an empty table; capture recovery inventory from reachable nodes afterward. No uninstall or node operation is performed.')
            ->action(function () {
                abort_unless(user()?->isRootAdmin(), 403);
                try {
                    self::repair();
                    Notification::make()->success()->title('Restic tables are ready. Refresh this page.')->persistent()->send();
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Restic installation repair failed')
                        ->body('No backups were intentionally deleted. Review the newest protected Panel log entry for the database or compatibility error.')->persistent()->send();
                }
            });
    }
}
