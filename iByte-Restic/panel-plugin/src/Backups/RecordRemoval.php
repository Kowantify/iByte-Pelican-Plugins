<?php

namespace IByte\Restic\Backups;

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use IByte\Restic\Integration\LifecycleGuard;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Explicit administrative metadata cleanup, never a substitute for node deletion.
final class RecordRemoval
{
    public static function force(User $user, string $uuid, string $password): void
    {
        self::remove($user, $uuid, $uuid, $password, true, force: true);
    }

    public static function repository(User $user, RecoveryRepository $repository, string $password): void
    {
        RecoveryVault::confirm($user, $password);
        Installation::assertReady();
        self::repositoryAfterConfirmedDeletion($user, $repository);
    }

    public static function repositoryAfterConfirmedDeletion(User $user, RecoveryRepository $repository): void
    {
        RecoveryVault::authorize($user);
        DB::transaction(function () use ($user, $repository) {
            $server = Server::where('uuid', $repository->server_uuid)->where('node_id', $repository->node_id)->lockForUpdate()->first();
            if ($server) {
                foreach ($server->backups()->where('backup_host_id', $repository->host_id)->lockForUpdate()->get() as $backup) {
                    self::discard($user, $backup->uuid, true);
                }
            }
            // Retained mappings can outlive native rows. An explicit repository
            // metadata override also retires those references, never its keys.
            DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repository->id)->update(['forgotten_at' => now()]);
            RecoveryVault::audit('repository-records-force-removed', $repository->id, $user->id);
        });
    }

    public static function remove(User $user, string $uuid, string $confirmation, string $password, bool $externalDeletionConfirmed, bool $force = false): void
    {
        RecoveryVault::authorize($user);
        Installation::assertReady();
        if (! $externalDeletionConfirmed || ! Str::isUuid($uuid) || ! hash_equals($uuid, $confirmation)) {
            throw ValidationException::withMessages(['confirmation' => 'Confirm external deletion and enter the complete selected backup UUID.']);
        }
        RecoveryVault::confirm($user, $password);
        self::discard($user, $uuid, $force);
    }

    private static function discard(User $user, string $uuid, bool $force): void
    {
        DB::transaction(function () use ($user, $uuid, $force) {
            $candidate = Backup::where('uuid', $uuid)->firstOrFail();
            $server = Server::whereKey($candidate->server_id)->lockForUpdate()->firstOrFail();
            if (! $force) {
                LifecycleGuard::assertNotReserved($server);
            }
            $backup = Backup::whereKey($candidate->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($backup->backupHost?->schema === 'restic', 403);
            if (! $force && $backup->is_locked) {
                throw new ConflictHttpException('Unlock this backup through native permissions before removing its record.');
            }
            $operations = DB::table('ibyte_restic_operations')->where('backup_uuid', $uuid)->lockForUpdate()->get();
            if (! $force && ($server->status === ServerState::RestoringBackup || $operations->contains(fn ($op) => $op->operation === 'restore' && in_array($op->status, ['queued', 'running', 'unknown'], true)))) {
                throw new ConflictHttpException('Restore state is unresolved. Reconcile it before removing this record; server protection is retained.');
            }
            $cutoff = now()->subSeconds(8100);
            if (! $force && ((! $backup->completed_at && $backup->created_at->greaterThan($cutoff))
                || $operations->contains(fn ($op) => in_array($op->operation, ['create', 'delete', 'prune', 'restore'], true)
                    && (($op->status === 'queued' && $op->created_at > $cutoff->toDateTimeString())
                        || ($op->status === 'running' && $op->updated_at > $cutoff->toDateTimeString()))))) {
                throw new ConflictHttpException('Recent backup work may still be active. Let it finish or reconcile it first; stale pending work can be dismissed after 135 minutes.');
            }
            // Preserve unresolved restore records and native protection. A metadata
            // override never declares the server safe or resumes its game process.
            DB::table('ibyte_restic_operations')->where('backup_uuid', $uuid)->where('operation', '!=', 'restore')->whereIn('status', ['queued', 'running', 'unknown'])
                ->update(['status' => 'dismissed', 'error' => 'Administrator confirmed external deletion and removed the Panel record; node state was not verified.', 'updated_at' => now()]);
            DB::table('ibyte_restic_operations')->insert([
                'request_uuid' => (string) Str::uuid(), 'backup_uuid' => $uuid, 'server_uuid' => $server->uuid,
                'node_id' => $server->node_id, 'host_id' => $backup->backup_host_id, 'operation' => 'record-remove',
                'payload' => json_encode(['actor_id' => $user->id, 'reason' => $force ? 'admin-force-metadata-removal' : 'external-deletion-confirmed', 'backup_name' => $backup->name, 'was_locked' => $backup->is_locked], JSON_THROW_ON_ERROR),
                'status' => 'dismissed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $uuid)->update(['forgotten_at' => now()]);
            RecoveryVault::audit('stale-record-removed', actor: $user->id);
            // Skip the deleted observer: it queues node pruning. Preserve escrow,
            // history and credentials and perform no node/filesystem operation.
            $backup->deleteQuietly();
        });
    }
}
