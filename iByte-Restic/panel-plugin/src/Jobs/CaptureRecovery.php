<?php

namespace IByte\Restic\Jobs;

use App\Models\Backup;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Recovery\RecoveryVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CaptureRecovery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public int $uniqueFor = 300;

    public int $backoff = 60;

    public function __construct(public string $backupUuid) {}

    public function uniqueId(): string
    {
        $backup = Backup::where('uuid', $this->backupUuid)->first();

        return $backup ? $backup->server_id.':'.$backup->backup_host_id : $this->backupUuid;
    }

    public function handle(): void
    {
        $backup = Backup::where('uuid', $this->backupUuid)->first();
        if ($backup?->server && $backup->backupHost?->schema === 'restic') {
            if ($backup->server->transfer || DB::table('ibyte_restic_operations')->where('server_uuid', $backup->server->uuid)->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('operation', 'lifecycle')->where('status', 'reserved'))
                ->orWhere(fn ($q) => $q->where('operation', 'repo-delete')->whereIn('status', ['reserved', 'queued', 'running', 'unknown'])))->exists()) {
                return;
            }
            RecoveryVault::capture($backup);
            RecoveryVault::finalize($backup);
            $completed = $backup->is_successful ? $backup : Backup::where('server_id', $backup->server_id)
                ->where('backup_host_id', $backup->backup_host_id)->where('is_successful', true)->latest('id')->first();
            if ($completed && ! DB::table('ibyte_restic_operations')->where('server_uuid', $backup->server->uuid)
                ->where('host_id', $backup->backup_host_id)->where('operation', 'stats')
                ->whereIn('status', ['queued', 'running', 'unknown'])->exists()) {
                app(Operations::class)->queue($completed, 'stats', (string) Str::uuid());
            }
        }
    }
}
