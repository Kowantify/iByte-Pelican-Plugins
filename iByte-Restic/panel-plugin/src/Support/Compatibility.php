<?php

namespace IByte\Restic\Support;

use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
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
        $pins = json_decode(file_get_contents(__DIR__.'/../../compatibility.json'), true, flags: JSON_THROW_ON_ERROR);
        $mismatches = [];
        foreach ($pins['panel_files'] as $path => $hash) {
            $contents = is_file(base_path($path)) && is_readable(base_path($path)) ? file_get_contents(base_path($path)) : false;
            $actual = $contents === false ? null : hash('sha256', str_replace("\r\n", "\n", $contents));
            if ($actual === null || ! hash_equals($hash, $actual)) {
                $mismatches[] = ['file' => $path, 'expected' => $hash, 'actual' => $actual];
            }
        }
        $dependencies = DependencyContract::inspect($pins['panel_runtime_packages'] ?? []);

        return ['matches' => $mismatches === [] && $dependencies === [], 'panel_tag' => $pins['panel']['tag'], 'panel_commit' => $pins['panel']['sha'],
            'file_mismatches' => $mismatches, 'dependency_mismatches' => $dependencies,
            'note' => 'Core/vendor contracts and tested runtime package versions/references remain pinned. Lockfile metadata, development packages and additional plugin packages may differ. This is compatibility checking, not a replacement for a security audit.'];
    }
}
