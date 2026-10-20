<?php

namespace IByte\Restic\Recovery;

use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use Filament\Notifications\Notification;
use IByte\Restic\Backups\Retention;
use IByte\Restic\Integration\LifecycleGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FinalBackupPreparation
{
    public static function applies(Server $server): bool
    {
        $host = Retention::host($server);

        return $host?->schema === 'restic' && $host->nodes()->whereKey($server->node_id)->exists();
    }

    // No password, callable or deferred destructive authorization is persisted.
    // The administrator reviews completion and confirms the native action again.
    public static function ready(User $actor, Server $server, string $password, string $confirmation, string $operation, ?int $destination = null): bool
    {
        RecoveryVault::confirm($actor, $password);
        if (! hash_equals($server->uuid, $confirmation)) {
            throw new ConflictHttpException('Server UUID confirmation does not match.');
        }
        abort_unless(in_array($operation, ['delete', 'transfer'], true), 409);
        $lock = Cache::lock('ibyte-restic:final-backup:'.$server->uuid, 180);
        if (! $lock->get()) {
            throw new ConflictHttpException('Another final-backup preparation is running. Refresh this server before retrying.');
        }
        try {
            $server->refresh();
            $host = Retention::host($server);
            if ($host?->schema !== 'restic' || $server->transfer) {
                throw new ConflictHttpException('Final backup requires the assigned Restic host and no active transfer.');
            }
            $intent = DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('operation', 'final-backup')->where('status', 'prepared')->first();
            if ($intent) {
                $payload = json_decode($intent->payload, true, flags: JSON_THROW_ON_ERROR);
                $backup = Backup::where('uuid', $intent->backup_uuid)->where('server_id', $server->id)->first();
                if ((int) $intent->node_id !== $server->node_id || (int) $intent->host_id !== $host->id || ($payload['action'] ?? null) !== $operation || ($payload['destination'] ?? null) !== $destination || ($payload['actor'] ?? null) !== $actor->id) {
                    throw new ConflictHttpException('A final backup was prepared for another administrator, action or destination. Review it before preparing another transition.');
                }
                if (! $backup) {
                    DB::table('ibyte_restic_operations')->where('request_uuid', $intent->request_uuid)->update(['status' => 'dismissed', 'error' => 'Prepared backup record was removed; no transition started.', 'updated_at' => now()]);
                    throw new ConflictHttpException('The prepared final backup record was removed. The server remains in place; confirm again to prepare a replacement.');
                }
                $createStatus = DB::table('ibyte_restic_operations')->where('request_uuid', $backup->uuid)->value('status');
                if ($createStatus === 'unknown') {
                    throw new ConflictHttpException('Final backup completion could not be verified. Review Needs attention before proceeding.');
                }
                if (! $backup->completed_at) {
                    self::pending();

                    return false;
                }
                if (! $backup->is_successful) {
                    DB::table('ibyte_restic_operations')->where('request_uuid', $intent->request_uuid)->update(['status' => 'failed', 'error' => 'Final backup failed; no server transition was started.', 'updated_at' => now()]);
                    throw new ConflictHttpException('Final backup failed. The server has not been deleted or transferred. Review the failure before retrying.');
                }
                $latest = $server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->where('is_successful', true)->whereNotNull('completed_at')->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($latest?->uuid !== $backup->uuid || $backup->completed_at->lt(now()->subMinutes(15))) {
                    throw new ConflictHttpException('The prepared final backup is no longer recent or latest. Preserve it, review newer backups and prepare a fresh final backup before proceeding.');
                }
                LifecycleGuard::assertClear($server, true);

                return true;
            }
            LifecycleGuard::assertClear($server, true);
            if ($server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->where('is_locked', true)->exists()) {
                throw new ConflictHttpException('Unlock existing Restic backups before preparing the final backup. They would otherwise prevent latest-only retention.');
            }
            DB::transaction(function () use ($actor, $server, $operation, $destination, $host) {
                $locked = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                if ($locked->node_id !== $server->node_id || Retention::host($locked)?->id !== $host->id) {
                    throw new ConflictHttpException('Server placement changed during final-backup preparation.');
                }
                if ($locked->backup_limit <= 0 || $locked->backups()->nonFailed()->count() >= $locked->backup_limit) {
                    throw new ConflictHttpException('A free backup slot is required for the final backup. Increase this server backup limit temporarily or explicitly remove a reviewed backup first. The server and existing recovery points have been preserved.');
                }
                $backup = app(InitiateBackupService::class)->setIgnoredFiles([])->setIsLocked(true)->handle($locked, 'Final backup before '.$operation);
                DB::table('ibyte_restic_operations')->insert(['request_uuid' => (string) Str::uuid(), 'backup_uuid' => $backup->uuid, 'server_uuid' => $server->uuid,
                    'node_id' => $server->node_id, 'host_id' => $backup->backup_host_id, 'operation' => 'final-backup', 'status' => 'prepared',
                    'payload' => json_encode(['actor' => $actor->id, 'action' => $operation, 'destination' => $destination], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            });
            self::pending();

            return false;
        } finally {
            $lock->release();
        }
    }

    public static function cancel(User $actor, Server $server, string $password): void
    {
        RecoveryVault::confirm($actor, $password);
        $lock = Cache::lock('ibyte-restic:final-backup:'.$server->uuid, 180);
        if (! $lock->get()) {
            throw new ConflictHttpException('Final-backup preparation is busy. Retry after it finishes.');
        }
        try {
            DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('operation', 'final-backup')->where('status', 'prepared')
                ->update(['status' => 'dismissed', 'error' => 'Preparation cleared by administrator; backup and locks preserved.', 'updated_at' => now()]);
            RecoveryVault::audit('final-backup-preparation-cleared', actor: $actor->id);
        } finally {
            $lock->release();
        }
    }

    private static function pending(): void
    {
        Notification::make()->success()->title('Final backup is being prepared')
            ->body('The server has not been deleted or transferred. Wait for the locked final backup to show Successful, then confirm the same native action again. Existing recovery points are preserved during preparation; a free backup slot is required.')->persistent()->send();
    }
}
