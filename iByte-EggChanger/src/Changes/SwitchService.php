<?php

namespace IByte\EggChanger\Changes;

use App\Enums\ServerState;
use App\Models\Server;
use App\Services\Eggs\EggChangerService;
use Illuminate\Support\Facades\DB;
use IByte\EggChanger\Models\Operation;
use IByte\EggChanger\Models\Entitlement;
use IByte\EggChanger\Packages\Evaluator;
use IByte\EggChanger\Support\Audit;
use RuntimeException;

/**
 * Plan/confirm/queue entry point used by the client page.
 *
 * Steps per AGENTS.md §7: authorize → fresh eligibility → journal row with
 * snapshot → acquire durable reservation → dispatch the queued job. Every
 * value comes from the server record at call time, never client-submitted.
 */
final class SwitchService
{
    /**
     * Queue a destructive clean switch. Throws RuntimeException with an
     * actionable message when any gate fails (no exceptions are leaked raw).
     *
     * @param  int  $actorId  authenticated acting user id
     */
    public function queue(Server $server, int $targetEggId, int $actorId): Operation
    {
        if (!Entitlement::allowed((int) $server->id)) {
            throw new RuntimeException('Game switching is not enabled for this server. Contact an administrator.');
        }

        // ---- Durable one-operation-per-server gate FIRST
        if (Reservation::journalHasActive($server->id)) {
            throw new RuntimeException(
                'This server already has an egg-change operation in progress. Check the progress panel or the server Settings.'
            );
        }

        if ($server->status === ServerState::Suspended) {
            throw new RuntimeException('This server is suspended. Ask an administrator to unsuspend it first.');
        }

        if ($server->isInConflictState() || !$server->isInstalled()) {
            throw new RuntimeException(
                'This server is not in a switchable state right now (install/restore/transfer in progress).'
            );
        }

        // ---- Fresh eligibility (never trust a stale preview)
        $evaluator = new Evaluator();
        $evaluator->assertEligible($server, $targetEggId);

        $package = $evaluator->grantingPackage($server, $targetEggId);

        if (!$package) {
            throw new RuntimeException('No enabled package grants this egg right now.');
        }

        if ($server->egg_id === $targetEggId) {
            throw new RuntimeException('This egg is already installed. Selecting it is not a destructive action.');
        }

        // ---- Durable journal row with the qualifying-rule snapshot
        /** @var Operation $operation */
        $operation = null;

        DB::transaction(function () use (&$operation, $server, $targetEggId, $package, $actorId): void {
            $operation = Operation::create([
                'uuid' => RootCleaner::newUuid(),
                'server_id' => $server->id,
                'server_uuid' => (string) $server->uuid,
                'actor_id' => $actorId,
                'source_egg_id' => (int) $server->egg_id,
                'target_egg_id' => $targetEggId,
                'package_id' => $package->id,
                'snapshot_memory_mb' => (int) $server->memory,
                'snapshot_disk_mb' => (int) $server->disk,
                'snapshot_min_memory_mb' => $package->min_memory_mb,
                'snapshot_min_disk_mb' => $package->min_disk_mb,
                'phase' => Operation::PHASE_QUEUED,
                'queued_at' => now(),
            ]);
        });

        // ---- Reservation fence
        if (!Reservation::acquire($server->id)) {
            // Journal already active-checked above; a second actor won the lock
            // between our check and now. Keep the row queued; it will run later
            // only if it is the one dispatched. Roll back this row instead.
            $operation->phase = Operation::PHASE_FAILED;
            $operation->failure_stage = 'queue';
            $operation->failure_message = 'Another operation won the per-server lock; this request was not queued.';
            $operation->finished_at = now();
            $operation->save();

            throw new RuntimeException('Another egg change just started for this server. Refresh and check the Operations status.');
        }

        // ---- Queue the real work on the panel's redis queue (worker verified present)
        dispatch(new SwitchJob($operation->id));

        Audit::write('ibyte:egg-changer.queued', [
            'operation_uuid' => $operation->uuid,
            'server_uuid' => $operation->server_uuid,
            'source_egg_id' => $operation->source_egg_id,
            'target_egg_id' => $operation->target_egg_id,
            'package_id' => $package->id,
        ]);

        return $operation->refresh();
    }

    /**
     * Live operation status for the client progress panel.
     *
     * @return array<string, mixed>|null
     */
    public static function latestFor(Server $server): ?array
    {
        $operation = Operation::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')->first();

        if (!$operation) {
            return null;
        }

        return [
            'uuid' => $operation->uuid,
            'phase' => $operation->phase,
            'active' => str_contains(
                implode(',', [Operation::PHASE_QUEUED, Operation::PHASE_BACKUP, Operation::PHASE_CLEANING, Operation::PHASE_CONFIGURING, Operation::PHASE_REINSTALLING]),
                $operation->phase
            ),
            'source_egg_id' => $operation->source_egg_id,
            'target_egg_id' => $operation->target_egg_id,
            'backup_uuid' => $operation->backup_uuid,
            'backup_successful' => $operation->backup_successful,
            'failure_stage' => $operation->failure_stage,
            // Do not expose historical exception text: older builds persisted raw
            // upstream failures that might include a bearer token/signed URL.
            'failure_message' => $operation->failure_message === null ? null
                : 'This operation needs attention. Contact support with its reference and failure stage.', 
            'queued_at' => $operation->queued_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Admin reconcile: confirm what actually happened on the node and close a
     * needs_reconciliation operation truthfully (never fabricating success).
     */
    public static function reconcile(Operation $operation, bool $completed): void
    {
        if ($operation->phase !== Operation::PHASE_RECONCILE) {
            throw new RuntimeException('Only needs-reconciliation operations can be reconciled.');
        }

        $operation->phase = $completed ? Operation::PHASE_COMPLETED : Operation::PHASE_FAILED;
        $operation->failure_message = 'Reconciled by an administrator' . ($completed ? ': install confirmed finished.' : ': install confirmed failed.');
        $operation->finished_at = $operation->finished_at ?? now();
        $operation->save();

        Reservation::release($operation->server_id);
    }

    /** Cancel a queued (not yet destructive) operation. */
    public static function cancelQueued(Operation $operation): void
    {
        if ($operation->phase !== Operation::PHASE_QUEUED) {
            throw new RuntimeException('Only queued operations that have not started destructive work can be cancelled.');
        }

        $operation->phase = Operation::PHASE_FAILED;
        $operation->failure_stage = 'cancelled';
        $operation->failure_message = 'Cancelled before destructive work started.';
        $operation->finished_at = now();
        $operation->save();

        Reservation::release($operation->server_id);
    }
}