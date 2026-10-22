<?php

namespace IByte\EggChanger\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation readiness: verifies this plugin's own schema exists before any
 * UI or job touches it. Distinct, actionable errors — never silent SQL failures.
 */
final class Readiness
{
    private const REQUIRED_TABLES = [
        'ibyte_egg_changer_packages',
        'ibyte_egg_changer_package_eggs',
        'ibyte_egg_changer_operations',
    ];

    public static function ready(): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] missing table names */
    public static function missing(): array
    {
        return array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table) => !Schema::hasTable($table)
        ));
    }

    public static function error(): string
    {
        $missing = self::missing();

        return $missing === []
            ? ''
            : 'Egg Changer is not fully installed. Missing tables: ' . implode(', ', $missing)
              . '. Re-run "php artisan p:plugin:install ibyte-egg-changer" or the plugin Import/Install action so its migrations run.';
    }

    /** Guard helper: throws with an actionable message when not ready. */
    public static function assertReady(): void
    {
        $error = self::error();
        if ($error !== '') {
            throw new \RuntimeException($error);
        }
    }

    /**
     * Native source contracts this plugin depends on. Cached static result;
     * checked per-request, never against live nodes.
     *
     * @return array<string, bool>
     */
    public static function contracts(): array
    {
        return [
            'EggChangerService' => class_exists(\App\Services\Eggs\EggChangerService::class),
            'ReinstallServerService' => class_exists(\App\Services\Servers\ReinstallServerService::class),
            'InitiateBackupService' => class_exists(\App\Services\Backups\InitiateBackupService::class),
            'DaemonFileRepository' => class_exists(\App\Repositories\Daemon\DaemonFileRepository::class),
            'DaemonServerRepository' => class_exists(\App\Repositories\Daemon\DaemonServerRepository::class),
        ];
    }

    /** True only when every native contract exists. */
    public static function contractsOk(): bool
    {
        foreach (self::contracts() as $ok) {
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** Distinct statuses for UI: ready | missing_tables | missing_contracts */
    public static function status(): string
    {
        if (!self::ready()) {
            return 'missing_tables';
        }

        return self::contractsOk() ? 'ready' : 'missing_contracts';
    }

    /**
     * Whether any operation is in a non-final phase for a server.
     * Used to enforce one active operation per server.
     */
    public static function hasActiveOperation(int $serverId): bool
    {
        if (!Schema::hasTable('ibyte_egg_changer_operations')) {
            return false;
        }

        return DB::table('ibyte_egg_changer_operations')
            ->where('server_id', $serverId)
            ->whereIn('phase', [
                \IByte\EggChanger\Models\Operation::PHASE_QUEUED,
                \IByte\EggChanger\Models\Operation::PHASE_BACKUP,
                \IByte\EggChanger\Models\Operation::PHASE_CLEANING,
                \IByte\EggChanger\Models\Operation::PHASE_CONFIGURING,
                \IByte\EggChanger\Models\Operation::PHASE_REINSTALLING,
            ])
            ->exists();
    }
}