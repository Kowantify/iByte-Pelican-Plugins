<?php

namespace IByte\Restic\Providers;

use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Admin\HealthActions;
use IByte\Restic\Admin\HostForm;
use IByte\Restic\Admin\RetentionActions;
use IByte\Restic\Admin\ServerLifecycleActions;
use IByte\Restic\Backups\DownloadController;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Console\DiagnoseNode;
use IByte\Restic\Console\DiagnoseRuntime;
use IByte\Restic\Console\RepairInstallation;
use IByte\Restic\Integration\BridgeRepository;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Integration\ModelObservers;
use IByte\Restic\Integration\RetainingBackupService;
use IByte\Restic\Recovery\RecoveryRevealController;
use IByte\Restic\Recovery\RepositoryExport;
use IByte\Restic\Recovery\RepositoryRemoval;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\ServerDestruction;
use IByte\Restic\ResticSchema;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\Maintenance;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ResticProvider extends ServiceProvider
{
    public function register(): void
    {
        HostForm::register();
        HealthActions::register();
        StorageReport::register();
        RetentionActions::register();
        $this->app->bind(InitiateBackupService::class, RetainingBackupService::class);
        $this->app->scoped(RetainedDeletion::class);
        $this->app->scoped(RepositoryRemoval::class);
        $this->app->scoped(ServerDestruction::class);
        ServerLifecycleActions::register();
        $this->app->bind(ServerDeletionService::class, GuardedServerDeletion::class);
        // Stock UI and API restore both resolve this exact non-final class through the container.
        // Source-pinned bridges delegate all non-Restic operations unchanged.
        $this->app->bind(DaemonBackupRepository::class, BridgeRepository::class);
        $this->app->afterResolving(BackupAdapterService::class, function (BackupAdapterService $service) {
            $service->register($this->app->make(ResticSchema::class));
        });
    }

    public function boot(): void
    {
        $this->commands([RepairInstallation::class, DiagnoseRuntime::class, DiagnoseNode::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => Maintenance::register($schedule));
        Route::middleware(['web', 'auth'])->get('/admin/restic-recovery', function () {
            abort_unless(user()?->isRootAdmin(), 403);

            return redirect('/admin/restic-backups/administrative');
        });
        ModelObservers::register();
        Route::middleware(['web', 'auth', 'signed', 'throttle:5,1'])
            ->get('/ibyte-restic/repository-export/{repository}', RepositoryExport::class)
            ->name('ibyte-restic.repository-export');
        Route::middleware(['web', 'auth', 'throttle:5,1'])
            ->get('/ibyte-restic/recovery/{repository}', RecoveryRevealController::class)
            ->name('ibyte-restic.recovery-reveal');
        Route::middleware(['web', 'auth', 'signed', 'throttle:10,1'])
            ->get('/ibyte-restic/download/{backup:uuid}', DownloadController::class)
            ->name('ibyte-restic.download');

    }
}
