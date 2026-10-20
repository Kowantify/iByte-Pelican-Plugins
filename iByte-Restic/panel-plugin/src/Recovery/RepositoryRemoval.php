<?php

namespace IByte\Restic\Recovery;

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Models\User;
use App\Services\Backups\DeleteBackupService;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class RepositoryRemoval
{
    private ?array $scope = null;

    public function active(Backup $backup): bool
    {
        return $this->scope === [$backup->server->uuid, $backup->server->node_id, $backup->backup_host_id];
    }

    public function run(User $user, RecoveryRepository $record, string $password, string $confirmation, bool $acknowledged): void
    {
        RecoveryVault::confirm($user, $password);
        Installation::assertReady();
        Compatibility::assertSupported();
        $repo = $record->fresh();
        $reject = fn ($message) => throw new ConflictHttpException($message);
        if (! $acknowledged || ! hash_equals($repo->server_uuid, $confirmation)) {
            $reject('Confirm permanent repository deletion and enter the complete server UUID.');
        }
        if ($repo->backend !== 'local') {
            $reject('S3 repository deletion is not supported. No snapshot or storage was changed.');
        }
        if (isset($repo->metadata['repository_deleted_at'])) {
            return;
        }
        $server = Server::where('uuid', $repo->server_uuid)->where('node_id', $repo->node_id)->first();
        if (! $server || $server->transfer || isset($repo->metadata['archived_at'])) {
            $reject('This repository is retained on a deleted/transferred source server. Online cleanup is unavailable; preserve its keys and use explicit offline node maintenance.');
        }
        $host = BackupHost::find($repo->host_id);
        if ($host?->schema !== 'restic' || ($host->configuration['profile'] ?? null) !== $repo->profile) {
            $reject('Original Restic host/profile is unavailable. No storage was changed.');
        }
        if (DB::transactionLevel() !== 0) {
            $reject('Repository deletion must start outside an existing transaction so recovery escrow and its request can commit first.');
        }
        $lock = Cache::lock('ibyte-restic:remove-repository:'.$repo->id, 900);
        if (! $lock->get()) {
            $reject('Another repository deletion request is already active.');
        }
        try {
            $request = $repo->metadata['delete_request_uuid'] ?? (string) Str::uuid();
            $probe = new Backup(['uuid' => $repo->metadata['delete_backup_uuid'] ?? (string) Str::uuid(), 'server_id' => $server->id, 'backup_host_id' => $host->id]);
            $probe->setRelation('server', $server)->setRelation('backupHost', $host);
            $transport = app(Transport::class);
            $identity = $transport->context($probe, false);
            if ($server->node->scheme !== 'https') {
                $reject('Repository deletion requires verified HTTPS on the original node.');
            }
            $ready = $transport->client($server->node)->get('/api/servers/'.$server->uuid.'/restic/readiness')->throw()->json();
            if (($ready['repository_delete'] ?? null) !== 'empty-local-v1' || ($ready['version'] ?? null) !== '3.0.0-preview.2'
                || ($ready['protocol'] ?? null) !== 1 || ($ready['ready'] ?? null) !== true || ($ready['profiles'][$repo->profile] ?? null) !== 'local') {
                $reject('Update the matching Wings addon before repository deletion. No snapshots were removed.');
            }
            $backups = $server->backups()->where('backup_host_id', $host->id)->get();
            $this->preflight($server, $backups, $request);
            if (! isset($repo->metadata['delete_request_uuid'])) {
                // Capture/activate credentials before a durable destructive intent.
                $repo = RecoveryVault::capture($probe);
                RecoveryVault::finalize($probe);
                $plan = $transport->request($probe, 'repository-plan', $identity);
                foreach (['host', 'node', 'server', 'profile'] as $key) {
                    if (($plan[$key] ?? null) !== $identity[$key]) {
                        $reject('Repository plan identity mismatch. No backups were removed.');
                    }
                }
                if (($plan['repository'] ?? null) !== $repo->metadata['repository'] || ($plan['deleted'] ?? true) !== false || ! is_array($plan['snapshots'] ?? null)) {
                    $reject('Repository plan could not be verified. No backups were removed.');
                }
                foreach ($plan['snapshots'] as $uuid => $snapshot) {
                    if (! $backups->contains('uuid', $uuid) || ! DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->where('backup_uuid', $uuid)->where('snapshot_id', $snapshot)->whereNull('forgotten_at')->exists()) {
                        $reject('Repository contains unrecognized snapshots. Reconcile them before deletion; no backups were removed.');
                    }
                }
                DB::transaction(function () use ($server, $repo, $host, $backups, $request, $probe, $identity, $user, $reject) {
                    $locked = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                    $currentBackups = $locked->backups()->where('backup_host_id', $host->id)->lockForUpdate()->get();
                    $this->preflight($locked, $currentBackups, $request);
                    if ($locked->node_id !== $repo->node_id || $locked->transfer || $currentBackups->pluck('uuid')->sort()->values()->all() !== $backups->pluck('uuid')->sort()->values()->all()) {
                        $reject('Backup/server state changed during repository preflight. Retry without bypassing locks.');
                    }
                    $current = RecoveryRepository::whereKey($repo->id)->lockForUpdate()->firstOrFail();
                    if (isset($current->metadata['delete_request_uuid'])) {
                        $reject('Another repository deletion request was reserved. Retry the existing action.');
                    }
                    $metadata = $current->metadata;
                    $metadata += ['delete_request_uuid' => $request, 'delete_backup_uuid' => $probe->uuid];
                    $current->update(['metadata' => $metadata]);
                    DB::table('ibyte_restic_operations')->insert(['request_uuid' => $request, 'backup_uuid' => $probe->uuid, 'server_uuid' => $server->uuid, 'node_id' => $server->node_id, 'host_id' => $host->id,
                        'operation' => 'repo-delete', 'status' => 'reserved', 'payload' => json_encode($identity, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    RecoveryVault::audit('repository-delete-authorized', $repo->id, $user->id);
                });
            }
            try {
                DB::transaction(function () use ($server, $repo, $request, $probe, $identity, $transport, $user, $reject) {
                    $locked = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                    $backups = $locked->backups()->where('backup_host_id', $repo->host_id)->lockForUpdate()->get();
                    if ($locked->node_id !== $repo->node_id || $locked->transfer) {
                        $reject('Source server changed during deletion preflight.');
                    }
                    $this->preflight($locked, $backups, $request);
                    // Revalidate the current approved location on every retry
                    // before forgetting anything, including a lost-response
                    // retry whose node already retired the repository.
                    $plan = $transport->request($probe, 'repository-plan', $identity);
                    foreach (['host', 'node', 'server', 'profile'] as $key) {
                        if (($plan[$key] ?? null) !== $identity[$key]) {
                            $reject('Repository plan identity changed before cleanup.');
                        }
                    }
                    if (($plan['repository'] ?? null) !== $repo->metadata['repository'] || ! is_bool($plan['deleted'] ?? null) || ! is_array($plan['snapshots'] ?? null)) {
                        $reject('Repository location or plan changed before cleanup. No additional snapshots were removed.');
                    }
                    foreach ($plan['snapshots'] as $uuid => $snapshot) {
                        if (! $backups->contains('uuid', $uuid) || ! DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->where('backup_uuid', $uuid)->where('snapshot_id', $snapshot)->whereNull('forgotten_at')->exists()) {
                            $reject('Repository contains new or unrecognized snapshots. Cleanup refused.');
                        }
                    }
                    $this->scope = [$locked->uuid, $locked->node_id, $repo->host_id];
                    try {
                        foreach ($backups as $backup) {
                            app(DeleteBackupService::class)->handle($backup);
                        }
                        $payload = $identity + ['operation' => 'repo-delete', 'request_id' => $request, 'repository_fingerprint' => hash('sha256', $repo->metadata['repository'])];
                        $state = $transport->request($probe, 'jobs', $payload);
                        $deadline = microtime(true) + 20;
                        while (($state['status'] ?? null) === 'running' && microtime(true) < $deadline) {
                            usleep(250000);
                            $state = $transport->request($probe, 'status', $payload);
                        }
                        foreach (['host', 'node', 'server', 'profile', 'backup', 'operation', 'request_id'] as $key) {
                            if (($state[$key] ?? null) !== $payload[$key]) {
                                $reject('Repository deletion identity is unverified. Preserve state and retry this same action.');
                            }
                        }
                        if (($state['status'] ?? null) !== 'succeeded') {
                            $reject('Repository deletion is pending, failed or uncertain. Keys and records are retained; retry the same action after checking node state.');
                        }
                        $current = $repo->fresh();
                        $metadata = $current->metadata;
                        $metadata['repository_deleted_at'] = now()->toIso8601String();
                        $metadata['repository_deleted_by'] = $user->id;
                        $current->update(['metadata' => $metadata]);
                        DB::table('ibyte_restic_operations')->where('request_uuid', $request)->update(['status' => 'succeeded', 'updated_at' => now()]);
                        RecoveryVault::audit('repository-deleted', $repo->id, $user->id);
                    } finally {
                        $this->scope = null;
                    }
                });
            } catch (Throwable $exception) {
                DB::table('ibyte_restic_operations')->where('request_uuid', $request)->update(['status' => 'unknown', 'error' => 'Explicit repository deletion not confirmed; preserve escrow and reconcile the same request.', 'updated_at' => now()]);
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    private function preflight(Server $server, $backups, string $request): void
    {
        if ($backups->contains(fn ($backup) => $backup->is_locked || ! $backup->completed_at || ! $backup->is_successful)) {
            throw new ConflictHttpException('Unlock protected backups and reconcile unfinished/failed records before deleting this repository.');
        }
        if (DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('request_uuid', '!=', $request)->whereIn('status', ['queued', 'running', 'unknown', 'reserved'])->exists()) {
            throw new ConflictHttpException('Other Restic work is unresolved. No repository deletion can start.');
        }
    }
}
