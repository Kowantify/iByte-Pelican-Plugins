<?php

namespace IByte\Restic\Integration;

use App\Models\Backup;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Recovery\FinalBackupPreparation;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\ServerDestruction;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Pinned internal service binding: the model deleting event would run too late,
// after upstream already asked Wings to destroy the server's data.
final class GuardedServerDeletion extends ServerDeletionService
{
    public function handle(Server $server): void
    {
        $retain = app(RetainedDeletion::class)->active($server);
        $destroy = app(ServerDestruction::class)->active($server);

        if (FinalBackupPreparation::applies($server) && ! $retain && ! $destroy) {
            // Application API callers (external billing systems such as
            // Paymenter) authenticate with an admin Application API key and
            // cannot answer an interactive password modal. They get the SAFE
            // default automatically: a fresh locked final Restic backup,
            // verified complete, before the server is deleted. The UI path
            // still requires its explicit retention choice + password.
            if (ApplicationApiCall::detect()) {
                $this->autoRetainAndDelete($server);

                return;
            }

            throw new ConflictHttpException('Restic blocks ordinary server deletion. Use the native confirmed Delete action to prepare and verify a final backup before deleting this server, or call again through a root-admin Application API key to run the automatic final-backup retention.');
        }
        LifecycleGuard::assertClear($server, $retain);
        parent::handle($server);
    }

    /**
     * API-only automatic retention: create the locked final backup, verify it
     * actually completed successfully, mark the retained-snapshot scope, then
     * let the native deletion proceed. Every step is source-verified; any
     * failure aborts BEFORE the server is touched (409 to the API caller).
     */
    private function autoRetainAndDelete(Server $server): void
    {
        $actor = ApplicationApiCall::user();
        Installation::assertReady();
        if (DB::transactionLevel() !== 0) {
            throw new ConflictHttpException('API retention must run outside an existing transaction.');
        }

        $latest = $this->latestSuccessful($server);
        $fresh = $latest && $latest->completed_at->gt(now()->subMinutes(15));

        if (! $fresh) {
            $backup = app(InitiateBackupService::class)->setIgnoredFiles([])->setIsLocked(true)
                ->handle($server, 'Automatic final backup before API deletion');
            $deadline = now()->addMinutes(30);
            while (now()->lessThan($deadline)) {
                $state = $backup->refresh();
                if ($state->completed_at !== null) {
                    break;
                }
                sleep(5);
            }
            $backup = $backup->refresh();
            if (! $backup->completed_at || ! $backup->is_successful) {
                throw new ConflictHttpException('Automatic final backup did not complete successfully; the server was NOT deleted. Resolve the backup failure, then retry the API deletion.');
            }
            $latest = $backup;
        } else {
            // Reuse the existing latest successful backup (verified completed
            // and still fresh).
        }

        // Mark the retention scope so LifecycleGuard/GuardedServerDeletion treat
        // this deletion as latest-only retention (bypasses the ordinary-guard
        // conflict, keeps the retained snapshot + key/owner history).
        app(RetainedDeletion::class)->retainForApiDeletion($server, $latest, $actor);

        try {
            LifecycleGuard::assertClear($server, true);
            parent::handle($server);
        } finally {
            app(RetainedDeletion::class)->clearApiRetention($server);
        }
    }

    private function latestSuccessful(Server $server): ?Backup
    {
        /** @var Backup|null $latest */
        $latest = $server->backups()
            ->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))
            ->where('is_successful', true)->whereNotNull('completed_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        return $latest;
    }
}
