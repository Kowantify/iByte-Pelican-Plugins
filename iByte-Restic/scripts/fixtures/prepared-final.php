<?php

use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Phase-two lifecycle fixtures only. The separate final-backup tests exercise
// actual native creation, queued completion and failed/pending transitions.
function fixturePreparedFinal(Server $server, Backup $backup, User $actor, string $operation = 'delete', ?int $destination = null): void
{
    DB::table('ibyte_restic_operations')->insert([
        'request_uuid' => (string) Str::uuid(), 'backup_uuid' => $backup->uuid, 'server_uuid' => $server->uuid,
        'node_id' => $server->node_id, 'host_id' => $backup->backup_host_id, 'operation' => 'final-backup', 'status' => 'prepared',
        'payload' => json_encode(['actor' => $actor->id, 'action' => $operation, 'destination' => $destination]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}
