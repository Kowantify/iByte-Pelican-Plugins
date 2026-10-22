<?php

namespace IByte\EggChanger\Changes;

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Eggs\EggChangerService;
use App\Services\Servers\ReinstallServerService;
use Illuminate\Support\Facades\Log;
use IByte\EggChanger\Models\Operation;
use IByte\EggChanger\Models\Entitlement;
use IByte\EggChanger\Support\Audit;
use Throwable;

/**
 * The queued clean-switch worker.
 *
 * Guarantees (per AGENTS.md §7, in the order they are actually executed):
 *  1. Re-verify eligibility + package still valid at queue-pop time.
 *  2. Verify a safe stopped state — never guessed from a stale status.
 *  3. Create a completed native backup FIRST (recovery point), verify it
 *     actually finished successfully (Backup::is_successful + completed_at).
 *  4. Only then clean scoped root data, swap egg with the native
 *     EggChangerService, sync to Wings and dispatch the real reinstall.
 *  5. Completion is proven by the operation rows + server refresh; a request
 *     acceptance is marked `reinstalling`, never `completed`.
 *
 * Failure/cancel semantics: any failure after cleanup records phase=failed (or
 * needs_reconciliation when the native reinstall was already dispatched) and
 * keeps the journal row + backup reference. The backup is NEVER deleted —
 * restoring it is a deliberate admin action.
 */
final class SwitchJob extends \Illuminate\Bus\Queueable implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable;
    use \Illuminate\Queue\InteractsWithQueue;
    use \Illuminate\Queue\SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $operationId
    ) {}

    public function handle(): void
    {
        $operation = Operation::query()->whereKey($this->operationId)->first();

        if (!$operation || $operation->phase !== Operation::PHASE_QUEUED) {
            return; // Already started by another worker run, or cancelled.
        }

        $server = Server::query()->find($operation->server_id);

        if (!$server) {
            $this->failOperation($operation, 'precheck', 'Server no longer exists.');

            return;
        }

        try {
            $this->run($operation, $server);
        } catch (Throwable $exception) {
            $this->failOperation(
                $operation,
                $operation->phase === Operation::PHASE_QUEUED ? 'backup' : $operation->phase,
                'The egg change failed. Please contact support with the operation reference.'
            );

            // Exception text from daemon/backup providers may contain temporary
            // tokens or signed URLs. Log only safe identifiers and error type.
            Log::error('ibyte-egg-changer: switch failed', [
                'operation_uuid' => $operation->uuid,
                'server_uuid' => $operation->server_uuid,
                'phase' => $operation->phase,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function run(Operation $operation, Server $server): void
    {
        if (!Entitlement::allowed((int) $server->id)) {
            throw new \RuntimeException('Game switching is no longer enabled for this server.');
        }

        // ---- 1. Re-verify eligibility (fresh evaluation, not the stale preview)
        $evaluator = new \IByte\EggChanger\Packages\Evaluator();
        $evaluator->assertEligible($server, $operation->target_egg_id);

        if ($server->status === ServerState::Suspended) {
            throw new \RuntimeException('Server is suspended; unsuspend it before switching eggs.');
        }

        if ($server->isInConflictState() || !$server->isInstalled()) {
            throw new \RuntimeException(
                'Server is not in a safe state (' . ($server->status?->value ?? 'free')
                . '): installing, restoring, transferring or under maintenance.'
            );
        }

        // ---- 2. Verify a safe stopped state against the live daemon
        $daemon = app(DaemonServerRepository::class)->setServer($server);
        $details = $daemon->getDetails(timeout: 5);
        $state = (string) ($details['state'] ?? '');

        if ($state !== 'offline') {
            throw new \RuntimeException(
                'The server is not stopped (state ' . $state . '). Stop it and retry the switch.'
            );
        }

        // ---- 3. Recovery point: real native backup, verified complete
        $operation->phase = Operation::PHASE_BACKUP;
        $operation->save();

        $initiate = app(InitiateBackupService::class);
        $backup = $initiate->handle($server, 'iByte Egg Changer recovery point');

        $operation->backup_uuid = $backup->uuid;
        $operation->save();

        $this->awaitBackup($server, $backup);

        if ((int) $backup->refresh()->is_successful !== 1 || $backup->completed_at === null) {
            throw new \RuntimeException('Recovery backup did not complete successfully; nothing was deleted.');
        }

        $operation->backup_successful = true;
        $operation->save();

        // ---- 4. Scoped cleanup (backup verified; recovery point exists)
        $operation->phase = Operation::PHASE_CLEANING;
        $operation->save();

        app(RootCleaner::class)->clean($server);

        // ---- 5. Native egg change (database side)
        $operation->phase = Operation::PHASE_CONFIGURING;
        $operation->save();

        app(EggChangerService::class)->handle($server, $operation->target_egg_id);

        // Push the fresh egg/image/startup/variables to Wings, then real reinstall.
        try {
            $daemon->sync();
        } catch (Throwable $syncException) {
            // The panel's own BuildModificationService treats a failed sync as
            // non-fatal: the daemon refetches config on boot; log and continue.
            Log::warning('ibyte-egg-changer: daemon sync failed before reinstall', [
                'server_uuid' => $server->uuid,
                'operation_uuid' => $operation->uuid,
                'exception_class' => $syncException::class,
            ]);
        }

        // ---- 6. Real native reinstall dispatch
        $operation->phase = Operation::PHASE_REINSTALLING;
        $operation->save();

        app(ReinstallServerService::class)->handle($server);

        // Request accepted. Actual completion is proven when the server's status
        // leaves `installing` (the native callback path). The journal flips to
        // needs_reconciliation if the wait window passes without a terminal state.
        $this->awaitReinstallCompletion($server, $operation);

        Audit::write('ibyte:egg-changer.switch', [
            'operation_uuid' => $operation->uuid,
            'server_uuid' => $server->uuid,
            'target_egg_id' => $operation->target_egg_id,
        ]);
    }

    /** Poll the native backup row until it is terminal (bounded). */
    private function awaitBackup(Server $server, Backup $backup): void
    {
        $deadline = now()->addMinutes(30);

        while (now()->lessThan($deadline)) {
            $fresh = $backup->refresh();

            if ($fresh->completed_at !== null) {
                return;
            }

            sleep(5);
        }

        throw new \RuntimeException('Recovery backup did not complete within 30 minutes; nothing was deleted.');
    }

    /**
     * Poll the server until the native install lifecycle reports a terminal
     * state. The remote callback records InstallFailed/ReinstallFailed or
     * clears status. Bounded wait; overdue -> needs_reconciliation, with the
     * backup preserved and no claim of success.
     */
    private function awaitReinstallCompletion(Server $server, Operation $operation): void
    {
        $deadline = now()->addMinutes(60);

        while (now()->lessThan($deadline)) {
            $fresh = Server::query()->whereKey($server->id)->first();

            if ($fresh && $fresh->isFailedInstall()) {
                $operation->phase = Operation::PHASE_FAILED;
                $operation->failure_stage = Operation::PHASE_REINSTALLING;
                $operation->failure_message = 'Native reinstall reported failure (' . $fresh->status?->value . '). Recovery backup ' . ($operation->backup_uuid ?? 'n/a') . ' is preserved.';
                $operation->finished_at = now();
                $operation->save();

                return;
            }

            if ($fresh && $fresh->status === null) {
                $operation->phase = Operation::PHASE_COMPLETED;
                $operation->finished_at = now();
                $operation->save();

                return;
            }

            sleep(10);
        }

        $operation->phase = Operation::PHASE_RECONCILE;
        $operation->failure_stage = Operation::PHASE_REINSTALLING;
        $operation->failure_message = 'Reinstall did not reach a terminal state within the wait window. Verify the install on the node, then reconcile in the Operations tab. Recovery backup preserved.';
        $operation->save();
    }

    /** Durable failure recording; release handled by the caller in failure paths. */
    private function failOperation(Operation $operation, string $stage, string $message): void
    {
        // If the native reinstall was already dispatched, reconciliation is the
        // truthful state — never a blind retry of a destructive operation.
        if ($operation->phase === Operation::PHASE_REINSTALLING) {
            $operation->phase = Operation::PHASE_RECONCILE;
        } else {
            $operation->phase = Operation::PHASE_FAILED;
        }

        $operation->failure_stage = $stage;
        $operation->failure_message = $message;
        $operation->finished_at = $operation->finished_at ?? now();
        $operation->save();
    }
}