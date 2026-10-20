<?php

namespace IByte\Restic\Recovery;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Jobs\DestroyServerRepositories;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// A password-confirmed server-bound scope, never a global deletion bypass.
// Persist immutable cleanup targets BEFORE native force deletion; passwords and
// executable callbacks are never saved in the queue or operation payload.
final class ServerDestruction
{
    private ?array $scope = null;

    public function active(Server $server): bool
    {
        return $this->scope === [$server->uuid, $server->node_id];
    }

    public function run(User $actor, Server $server, string $password): string
    {
        RecoveryVault::confirm($actor, $password);
        abort_unless($actor->can('delete server', $server), 403);
        Installation::assertReady();
        Compatibility::assertSupported();
        if (DB::transactionLevel() !== 0 || in_array(config('queue.default'), ['sync', 'null'], true)) {
            throw new ConflictHttpException('Destructive server deletion requires a durable queue and a committed cleanup intent.');
        }
        $lock = Cache::lock('ibyte-restic:final-backup:'.$server->uuid, 180);
        if (! $lock->get()) {
            throw new ConflictHttpException('Another server lifecycle confirmation is running.');
        }
        try {
            $request = DB::transaction(function () use ($actor, $server) {
                $locked = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                if ($locked->uuid !== $server->uuid || $locked->node_id !== $server->node_id || $locked->transfer) {
                    throw new ConflictHttpException('Server identity changed or a transfer is active. Complete or cancel the transfer before destruction.');
                }
                $previous = DB::table('ibyte_restic_operations')->where('server_uuid', $locked->uuid)->where('operation', 'lifecycle')->where('status', 'reserved')->lockForUpdate()->first();
                if ($previous) {
                    $intent = json_decode($previous->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (($intent['kind'] ?? null) === 'server-destroy') {
                        if ((int) $previous->node_id !== $locked->node_id) {
                            throw new ConflictHttpException('Original destruction node changed. Reconcile its saved targets before retrying.');
                        }
                        $intent['actor_id'] = $actor->id;
                        DB::table('ibyte_restic_operations')->where('request_uuid', $previous->request_uuid)->update(['payload' => json_encode($intent, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                        RecoveryVault::audit('server-destruction-reconfirmed', actor: $actor->id);

                        return $previous->request_uuid;
                    }
                    // The explicit destructive choice supersedes retention, not
                    // an active transfer or node restore journal/protection.
                    DB::table('ibyte_restic_operations')->where('request_uuid', $previous->request_uuid)->update(['status' => 'dismissed', 'error' => 'Superseded by password-confirmed server destruction.', 'updated_at' => now()]);
                }
                $repos = RecoveryRepository::where('server_uuid', $locked->uuid)->lockForUpdate()->get();
                // Captured inventory is required even when the original node is
                // offline. Never guess an arbitrary filesystem path for cleanup.
                foreach ($locked->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->get() as $backup) {
                    if (! $repos->contains(fn ($repo) => (int) $repo->host_id === $backup->backup_host_id && (int) $repo->node_id === $locked->node_id)) {
                        throw new ConflictHttpException('A Restic host lacks captured repository inventory. Capture or reconcile its location before combined destruction; no server was deleted.');
                    }
                }
                $targets = [];
                $request = (string) Str::uuid();
                foreach ($repos as $repo) {
                    if (isset($repo->metadata['repository_deleted_at'])) {
                        continue;
                    }
                    if ($repo->backend !== 'local' || empty($repo->metadata['repository'])) {
                        throw new ConflictHttpException('Combined physical destruction requires captured local repositories. S3 cleanup is not supported; no server was deleted.');
                    }
                    $targets[] = self::identity($repo);
                    $repo->update(['metadata' => array_merge($repo->metadata, ['server_destroy_request_uuid' => $request,
                        'delete_request_uuid' => $repo->metadata['delete_request_uuid'] ?? $request, 'automatic_maintenance' => false])]);
                    RecoveryVault::audit('server-repository-destruction-authorized', $repo->id, $actor->id);
                }
                DB::table('ibyte_restic_operations')->insert(['request_uuid' => $request, 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $locked->uuid,
                    'node_id' => $locked->node_id, 'host_id' => $targets[0]['host_id'] ?? 0, 'operation' => 'lifecycle', 'status' => 'reserved',
                    'payload' => json_encode(['kind' => 'server-destroy', 'actor_id' => $actor->id, 'repositories' => $targets], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('ibyte_restic_operations')->where('server_uuid', $locked->uuid)->where('operation', 'final-backup')->where('status', 'prepared')
                    ->update(['status' => 'dismissed', 'error' => 'Administrator chose destruction without retaining a final backup.', 'updated_at' => now()]);

                return $request;
            });
            $this->scope = [$server->uuid, $server->node_id];
            try {
                app(ServerDeletionService::class)->withForce()->handle($server);
            } finally {
                $this->scope = null;
                if (! Server::where('uuid', $server->uuid)->exists()) {
                    DB::table('ibyte_restic_operations')->where('request_uuid', $request)->update(['status' => 'queued', 'updated_at' => now()]);
                    DestroyServerRepositories::dispatch($request)->afterCommit();
                }
            }

            return $request;
        } finally {
            $lock->release();
        }
    }

    public static function identity(RecoveryRepository $repo): array
    {
        return ['id' => $repo->id, 'host_id' => (int) $repo->host_id, 'node_id' => (int) $repo->node_id,
            'server_uuid' => $repo->server_uuid, 'profile' => $repo->profile, 'backend' => $repo->backend,
            'generation' => (int) ($repo->metadata['generation'] ?? 0), 'fingerprint' => hash('sha256', $repo->metadata['repository'] ?? '')];
    }

    public static function dispatchPending(): void
    {
        DB::table('ibyte_restic_operations')->where('operation', 'lifecycle')->whereIn('status', ['reserved', 'queued', 'running', 'unknown'])->orderBy('created_at')->limit(100)->get()
            ->each(function ($row) {
                $payload = json_decode($row->payload, true);
                if (($payload['kind'] ?? null) === 'server-destroy' && ! Server::where('uuid', $row->server_uuid)->exists()) {
                    DB::table('ibyte_restic_operations')->where('request_uuid', $row->request_uuid)->where('status', 'reserved')->update(['status' => 'queued', 'updated_at' => now()]);
                    DestroyServerRepositories::dispatch($row->request_uuid)->afterCommit();
                }
            });
    }
}
