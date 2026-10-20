<?php

namespace IByte\Restic\Recovery;

use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use IByte\Restic\Jobs\PollRepositoryMaintenance;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RetainedMaintenance
{
    public static function queue(User $actor, RecoveryRepository $repository, string $operation): string
    {
        RecoveryVault::authorize($actor);
        Compatibility::assertSupported();
        Installation::assertReady();
        abort_unless(in_array($operation, ['check', 'stats', 'prune'], true), 403);

        return DB::transaction(function () use ($actor, $repository, $operation) {
            $record = RecoveryRepository::whereKey($repository->id)->lockForUpdate()->firstOrFail();
            if (isset($record->metadata['repository_deleted_at']) || isset($record->metadata['delete_request_uuid']) || empty($record->metadata['repository'])) {
                throw new ConflictHttpException('This repository is removed, reserved for deletion or lacks a captured location.');
            }
            if (Node::whereKey($record->node_id)->where('scheme', 'https')->doesntExist()) {
                throw new ConflictHttpException('The original HTTPS node must remain configured for repository maintenance.');
            }
            if (DB::table('ibyte_restic_operations')->where('host_id', $record->host_id)->where('node_id', $record->node_id)->where('server_uuid', $record->server_uuid)
                ->whereIn('status', ['queued', 'running', 'unknown', 'reserved'])->exists()) {
                throw new ConflictHttpException('Repository work is pending or needs reconciliation. Review it before queuing maintenance.');
            }
            if (DB::table('ibyte_restic_operations')->where('host_id', $record->host_id)->where('node_id', $record->node_id)->where('server_uuid', $record->server_uuid)
                ->where('operation', $operation)->where('created_at', '>', now()->subMinutes(5))->exists()) {
                throw new ConflictHttpException('This maintenance operation was requested recently. Wait five minutes before requesting it again.');
            }
            $request = (string) Str::uuid();
            $payload = ['host' => $record->host_id, 'node' => $record->node_id, 'server' => $record->server_uuid, 'profile' => $record->profile, 'backend' => $record->backend,
                'generation' => (int) ($record->metadata['generation'] ?? 0), 'backup' => (string) Str::uuid(), 'request_id' => $request, 'operation' => $operation,
                'repository_fingerprint' => hash('sha256', $record->metadata['repository'])];
            DB::table('ibyte_restic_operations')->insert(['request_uuid' => $request, 'backup_uuid' => $payload['backup'], 'server_uuid' => $record->server_uuid,
                'node_id' => $record->node_id, 'host_id' => $record->host_id, 'operation' => $operation, 'status' => 'queued', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            RecoveryVault::audit('retained-maintenance-authorized', $record->id, $actor->id);
            PollRepositoryMaintenance::dispatch($request)->afterCommit();

            return $request;
        });
    }

    public static function automatic(): void
    {
        $actor = User::whereHas('roles', fn ($query) => $query->where('name', Role::ROOT_ADMIN)->where('guard_name', 'web'))->first();
        if (! $actor) {
            return;
        }
        RecoveryRepository::select(['id', 'metadata', 'host_id', 'node_id', 'server_uuid'])->chunkById(100, function ($repositories) use ($actor) {
            foreach ($repositories as $repository) {
                if (empty($repository->metadata['archived_at']) || ($repository->metadata['automatic_maintenance'] ?? false) !== true || isset($repository->metadata['repository_deleted_at'])) {
                    continue;
                }
                foreach (['check' => 7, 'stats' => 1, 'prune' => 7] as $operation => $days) {
                    $recent = DB::table('ibyte_restic_operations')->where('host_id', $repository->host_id)->where('node_id', $repository->node_id)->where('server_uuid', $repository->server_uuid)
                        ->where('operation', $operation)->where('created_at', '>', now()->subDays($days))->exists();
                    if (! $recent) {
                        try {
                            self::queue($actor, $repository, $operation);
                        } catch (ConflictHttpException) {
                            // Busy/offline/unverified repositories remain intact. The next
                            // native scheduler tick retries; worker failures alert admins.
                        }
                        break;
                    }
                }
            }
        });
    }
}
