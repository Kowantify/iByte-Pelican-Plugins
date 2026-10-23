<?php

namespace IByte\Restic\Support;

use App\Repositories\Daemon\DaemonBackupRepository;
use App\Extensions\BackupAdapter\BackupAdapterSchemaInterface;
use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
use App\Services\Servers\TransferServerService;
use IByte\Restic\Integration\BridgeRepository;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Integration\RetainingBackupService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Compatibility
{
    public static function assertSupported(): void
    {
        self::assertBinding(InitiateBackupService::class, RetainingBackupService::class, 'backup retention');
        self::assertBinding(DaemonBackupRepository::class, BridgeRepository::class, 'native restore');
        self::assertBinding(ServerDeletionService::class, GuardedServerDeletion::class, 'server deletion safety');
        self::assertPanelSource();
    }

    private static function assertBinding(string $service, string $expected, string $purpose): void
    {
        $resolved = app($service);
        if ($resolved instanceof $expected) {
            return;
        }

        try {
            $file = (new \ReflectionClass($resolved))->getFileName() ?: '[internal]';
        } catch (\Throwable) {
            $file = '[unavailable]';
        }
        $details = ['service' => $service, 'expected' => $expected, 'actual' => $resolved::class,
            'actual_file' => $file, 'pid' => getmypid(), 'sapi' => PHP_SAPI];
        logger()->error('Restic compatibility binding mismatch', $details);
        throw new RuntimeException('Restic disabled: '.$purpose.' binding mismatch in this process. Resolved '.$resolved::class.' from '.$file.' (PID '.getmypid().', '.PHP_SAPI.'). Compare the protected error from this queue/web process with `php artisan restic:diagnose`; do not retry backups until the binding is resolved.');
    }

    public static function assertPanelSource(): void
    {
        $source = self::panelSource();
        if (! $source['matches']) {
            $files = array_column($source['file_mismatches'], 'file');
            $packages = array_column($source['dependency_mismatches'], 'package');
            // Identify paths/packages in protected logs, but never include
            // local file contents, dependency credentials or arbitrary errors.
            logger()->warning('Restic Panel compatibility mismatch', ['files' => $files, 'packages' => $packages]);
            throw new HttpException(409, 'Restic is unavailable because Panel files or tested runtime dependencies differ. Ask a root administrator to open Restic Backups > Administrative > Panel diagnostics or run php artisan restic:diagnose. Existing backups and keys are preserved.', headers: ['Retry-After' => 0]);
        }
    }

    public static function panelSource(): array
    {
        $mismatches = [];
        $contracts = [
            BackupAdapterSchemaInterface::class => ['getId', 'getName', 'getConfigurationForm', 'createBackup', 'deleteBackup', 'getDownloadLink'],
            BackupAdapterService::class => ['register', 'getMappings', 'get'],
            InitiateBackupService::class => ['handle', 'setIgnoredFiles', 'setIsLocked'],
            DaemonBackupRepository::class => ['setNode', 'getHttpClient'],
            ServerDeletionService::class => ['handle'],
            TransferServerService::class => ['handle'],
        ];
        foreach ($contracts as $class => $methods) {
            // Some entries in this inventory are interfaces, notably the
            // native backup schema contract. PHP class_exists() deliberately
            // returns false for interfaces; check both kinds of contract.
            if (! interface_exists($class) && ! class_exists($class)) {
                $mismatches[] = ['file' => $class, 'expected' => 'class with required methods', 'actual' => null];
                continue;
            }
            foreach ($methods as $method) {
                if (! method_exists($class, $method)) {
                    $mismatches[] = ['file' => $class.'::'.$method, 'expected' => 'available method', 'actual' => null];
                }
            }
        }

        return ['matches' => $mismatches === [], 'panel_tag' => 'runtime-detected', 'panel_commit' => null,
            'file_mismatches' => $mismatches, 'dependency_mismatches' => [],
            'note' => 'Compatibility is detected from the Panel services and methods used at runtime; exact source hashes, composer.lock and version metadata are not required. A method-presence check cannot prove upstream implementation semantics, so run the release acceptance checks after major Panel changes.'];
    }
}
