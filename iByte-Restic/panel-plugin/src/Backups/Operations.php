<?php

namespace IByte\Restic\Backups;

use App\Models\Backup;
use IByte\Restic\Integration\LifecycleGuard;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class Operations
{
    public function __construct(private Transport $transport) {}

    public function queue(Backup $backup, string $operation, string $request): void
    {
        LifecycleGuard::assertNotReserved($backup->server);
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            throw new RuntimeException('Restic requires a durable Panel queue worker.');
        }
        if ($operation === 'create') {
            RecoveryVault::capture($backup, allowRenewal: true);
        }
        $payload = $this->transport->context($backup, $operation !== 'prune') + [
            'operation' => $operation, 'request_id' => $request,
            'ignore' => $operation === 'create' ? $backup->ignored_files : [],
        ];
        DB::table('ibyte_restic_operations')->insertOrIgnore([
            'request_uuid' => $request, 'backup_uuid' => $backup->uuid, 'server_uuid' => $backup->server->uuid,
            'node_id' => $backup->server->node_id, 'host_id' => $backup->backup_host_id,
            'operation' => $operation, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PollOperation::dispatch($request)->delay($operation === 'prune' ? now()->addMinutes(10) : now())->afterCommit();
    }

    public function pruneAfterForget(Backup $backup): void
    {
        // Trusted housekeeping only: it cannot select/forget snapshots. Delay and
        // coalesce per repository so hourly/manual bursts do not each repack it.
        if (DB::table('ibyte_restic_operations')->where('server_uuid', $backup->server->uuid)
            ->where('host_id', $backup->backup_host_id)->where('operation', 'prune')
            ->whereIn('status', ['queued', 'running', 'unknown'])->exists()) {
            return;
        }
        $this->queue($backup, 'prune', (string) Str::uuid());
    }
}
