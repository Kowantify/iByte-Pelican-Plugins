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
        if (! (app(InitiateBackupService::class) instanceof RetainingBackupService)) {
            throw new RuntimeException('Restic disabled: another plugin replaced the backup retention compatibility binding.');
        }
        if (! (app(DaemonBackupRepository::class) instanceof BridgeRepository)) {
            throw new RuntimeException('Restic disabled: another plugin replaced the native restore compatibility binding.');
        }
        $deletion = app(ServerDeletionService::class);
        if (! ($deletion instanceof GuardedServerDeletion)) {
            throw new RuntimeException('Restic disabled: the server deletion safety binding is unavailable. Resolved '.$deletion::class.'; expected '.GuardedServerDeletion::class.' (PID '.getmypid().'). Run php artisan restic:diagnose and compare a fresh process after restarting the actual queue service. A missing binding alone does not identify another plugin as its cause. Root admins can remove externally deleted records through Restic Backups > Remove stale Panel record without node access.');
        }
        self::assertPanelSource();
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
            if (! class_exists($class)) {
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
