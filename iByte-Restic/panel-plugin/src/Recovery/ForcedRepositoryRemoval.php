<?php

namespace IByte\Restic\Recovery;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use IByte\Restic\Backups\RecordRemoval;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Explicit root override, deliberately independent of native snapshot deletion.
// The node derives its own allowlisted path; Panel never supplies a filesystem path.
final class ForcedRepositoryRemoval
{
    public function run(User $user, RecoveryRepository $record, string $password): void
    {
        RecoveryVault::confirm($user, $password);
        $this->execute($user, $record);
    }

    public function resumeServerDestruction(User $user, RecoveryRepository $record, string $request): void
    {
        RecoveryVault::authorize($user);
        $row = DB::table('ibyte_restic_operations')->where('request_uuid', $request)->where('operation', 'lifecycle')->whereIn('status', ['queued', 'running', 'unknown'])->first();
        $payload = $row ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : [];
        $repo = $record->fresh();
        $target = collect($payload['repositories'] ?? [])->firstWhere('id', $record->id);
        if (! $row || ($payload['kind'] ?? null) !== 'server-destroy' || ($payload['actor_id'] ?? null) !== $user->id
            || ! $repo || $row->server_uuid !== $repo->server_uuid || Server::where('uuid', $row->server_uuid)->exists()
            || $target !== ServerDestruction::identity($repo) || ($repo->metadata['server_destroy_request_uuid'] ?? null) !== $request) {
            throw new ConflictHttpException('Committed destruction scope or original repository identity is unavailable. No storage was changed.');
        }
        $this->execute($user, $repo);
    }

    private function execute(User $user, RecoveryRepository $record): void
    {
        RecoveryVault::authorize($user);
        Installation::assertReady();
        Compatibility::assertPanelSource();
        if (DB::transactionLevel() !== 0) {
            throw new ConflictHttpException('Repository deletion must start outside an existing transaction.');
        }
        $lock = Cache::lock('ibyte-restic:remove-repository:'.$record->id, 90);
        if (! $lock->get()) {
            throw new ConflictHttpException('Repository deletion is already in progress.');
        }
        try {
            $repo = $record->fresh();
            if (isset($repo->metadata['repository_deleted_at'])) {
                return;
            }
            if ($repo->backend !== 'local') {
                throw new ConflictHttpException('Physical S3 repository deletion is not supported. Remove Panel records is available without node access.');
            }
            $node = Node::find($repo->node_id);
            if (! $node || $node->scheme !== 'https' || empty($repo->metadata['repository'])) {
                throw new ConflictHttpException('Original node with verified HTTPS and captured repository location is required. Remove Panel records remains available offline.');
            }
            $client = app(Transport::class)->client($node);
            $ready = $client->get('/api/restic/readiness')->throw()->json();
            if (($ready['force_repository_delete'] ?? null) !== 'local-v1' || ($ready['version'] ?? null) !== '3.0.0-preview.2'
                || ($ready['protocol'] ?? null) !== 1 || ($ready['ready'] ?? false) !== true || ($ready['profiles'][$repo->profile] ?? null) !== 'local') {
                throw new ConflictHttpException('Update the original Wings node: force repository deletion capability is unavailable. No storage was changed.');
            }
            // A separate force intent can supersede a stuck conservative delete.
            // Persist before HTTP; retries use exactly the same idempotency key.
            $payload = DB::transaction(function () use ($repo, $user) {
                $current = RecoveryRepository::whereKey($repo->id)->lockForUpdate()->firstOrFail();
                $metadata = $current->metadata;
                $request = $metadata['force_delete_request_uuid'] ?? (string) Str::uuid();
                $backup = $metadata['force_delete_backup_uuid'] ?? (string) Str::uuid();
                $payload = ['host' => $current->host_id, 'node' => $current->node_id, 'server' => $current->server_uuid,
                    'profile' => $current->profile, 'backend' => 'local', 'backup' => $backup, 'request_id' => $request,
                    'operation' => 'repo-force-delete', 'repository_fingerprint' => hash('sha256', $metadata['repository'])];
                if (($metadata['generation'] ?? 0) > 0) {
                    $payload['generation'] = (int) $metadata['generation'];
                }
                $metadata['force_delete_request_uuid'] = $request;
                $metadata['force_delete_backup_uuid'] = $backup;
                $metadata['delete_request_uuid'] ??= $request;
                $current->update(['metadata' => $metadata]);
                DB::table('ibyte_restic_operations')->insertOrIgnore(['request_uuid' => $request, 'backup_uuid' => $backup, 'server_uuid' => $current->server_uuid,
                    'node_id' => $current->node_id, 'host_id' => $current->host_id, 'operation' => 'repo-delete', 'status' => 'reserved',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                RecoveryVault::audit('repository-force-delete-authorized', $current->id, $user->id);

                return $payload;
            });
            $state = $client->post('/api/restic/repository-delete', $payload)->throw()->json();
            $deadline = microtime(true) + 15;
            while (($state['status'] ?? null) === 'running' && microtime(true) < $deadline) {
                usleep(250000);
                $state = $client->post('/api/restic/repository-delete/status', $payload)->throw()->json();
            }
            foreach (['host', 'node', 'server', 'profile', 'backup', 'operation', 'request_id'] as $key) {
                if (($state[$key] ?? null) !== $payload[$key]) {
                    throw new ConflictHttpException('Deletion response identity is unverified. Retry the same repository action; keys and records remain.');
                }
            }
            if (($state['generation'] ?? 0) !== ($payload['generation'] ?? 0)) {
                throw new ConflictHttpException('Repository deletion generation is unverified; metadata retained.');
            }
            if (($state['status'] ?? null) !== 'succeeded') {
                throw new ConflictHttpException('Physical deletion is not confirmed. The node may be busy or deletion may still be running. Retry the same action; records and keys remain.');
            }
            DB::transaction(function () use ($repo, $user, $payload) {
                // Reauthentication was done before I/O; no second rate-limit debit.
                RecordRemoval::repositoryAfterConfirmedDeletion($user, $repo);
                $current = $repo->fresh();
                $metadata = $current->metadata;
                $metadata['repository_deleted_at'] = now()->toIso8601String();
                $metadata['repository_deleted_by'] = $user->id;
                $metadata['forced_deletion'] = true;
                $current->update(['metadata' => $metadata]);
                DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->update(['forgotten_at' => now()]);
                DB::table('ibyte_restic_operations')->where('server_uuid', $repo->server_uuid)->where('node_id', $repo->node_id)->where('host_id', $repo->host_id)
                    ->when(isset($repo->metadata['server_destroy_request_uuid']), fn ($query) => $query->where('request_uuid', '!=', $repo->metadata['server_destroy_request_uuid']))
                    ->where('operation', '!=', 'restore')->whereIn('status', ['queued', 'running', 'unknown', 'reserved'])->update(['status' => 'dismissed', 'updated_at' => now()]);
                DB::table('ibyte_restic_operations')->where('request_uuid', $payload['request_id'])->update(['status' => 'succeeded', 'updated_at' => now()]);
                RecoveryVault::audit('repository-force-deleted', $repo->id, $user->id);
            });
        } finally {
            $lock->release();
        }
    }
}
