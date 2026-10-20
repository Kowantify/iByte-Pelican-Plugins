<?php

namespace IByte\Restic\Jobs;

use App\Events\Server\BackupCompleted;
use App\Facades\Activity;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\FailureAlerts;
use IByte\Restic\Support\OperationDiagnostics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PollOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1000;

    public int $timeout = 45;

    public function __construct(public string $requestUuid) {}

    public function handle(Transport $transport): void
    {
        $lock = Cache::lock('ibyte-restic:'.$this->requestUuid, 40);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $record = DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->first();
            if (! $record || in_array($record->status, ['succeeded', 'failed', 'unknown', 'dismissed'], true)) {
                return;
            }
            if (now()->diffInSeconds($record->created_at, absolute: true) > ($record->operation === 'prune' ? 8100 : 7500)) {
                $this->markUnknown();

                return;
            }
            $backup = Backup::where('uuid', $record->backup_uuid)->first();
            if (! $backup && $record->operation === 'prune') {
                // Housekeeping remains reconcilable after the forgotten native
                // backup row is removed, including deletion of the last backup.
                $server = Server::where('uuid', $record->server_uuid)->where('node_id', $record->node_id)->first();
                $host = BackupHost::find($record->host_id);
                if ($server && $host?->schema === 'restic') {
                    $backup = new Backup(['uuid' => $record->backup_uuid, 'server_id' => $server->id, 'backup_host_id' => $host->id]);
                    $backup->setRelation('server', $server)->setRelation('backupHost', $host);
                }
            }
            if (! $backup) {
                $this->markUnknown();

                return;
            }
            $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
            try {
                if ($record->operation === 'create' && $record->status === 'queued') {
                    RecoveryVault::prepareQueuedBackup($backup);
                }
                $state = $transport->request($backup, $record->status === 'queued' ? 'jobs' : 'status', $payload);
            } catch (Throwable) {
                // Ambiguous network response is not failure/success. Replay the same persisted request ID.
                $this->release(10);

                return;
            }
            if (($state['generation'] ?? 0) !== ($payload['generation'] ?? 0) || ($state['request_id'] ?? '') !== $record->request_uuid || ($state['server'] ?? '') !== $record->server_uuid || ($state['backup'] ?? '') !== $record->backup_uuid || (int) ($state['host'] ?? 0) !== (int) $record->host_id || (int) ($state['node'] ?? 0) !== (int) $record->node_id || ($state['operation'] ?? '') !== $record->operation || ($state['profile'] ?? '') !== $payload['profile']) {
                $this->markUnknown();

                return;
            }
            if (($state['status'] ?? '') === 'running') {
                DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->whereIn('status', ['queued', 'running'])->update(['status' => 'running', 'updated_at' => now()]);
                $this->release(10);

                return;
            }
            if (! in_array($state['status'] ?? '', ['succeeded', 'failed'], true)) {
                $this->markUnknown();

                return;
            }
            $success = $state['status'] === 'succeeded';
            if ($success && ! StorageReport::validMetrics($state)) {
                $this->markUnknown();

                return;
            }
            if ($success && $record->operation === 'create' && (! preg_match('/^[a-f0-9]{64}$/D', $state['snapshot'] ?? '') || ! is_int($state['bytes'] ?? null) || $state['bytes'] < 0)) {
                $this->markUnknown();

                return;
            }
            DB::transaction(function () use ($backup, $record, $state, $success) {
                $row = DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->lockForUpdate()->first();
                if (in_array($row->status, ['succeeded', 'failed', 'unknown', 'dismissed'], true)) {
                    return;
                }
                DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->update([
                    'status' => $state['status'], 'snapshot_id' => $success ? ($state['snapshot'] ?? null) : null,
                    'bytes' => $success ? max(0, (int) ($state['bytes'] ?? 0)) : 0,
                    'metrics' => $success ? json_encode(array_intersect_key($state, array_flip(['storage', 'repository'])), JSON_THROW_ON_ERROR) : null,
                    'error' => $success ? (isset($state['maintenance_error']) ? 'Backup operation verified; repository statistics will retry automatically.' : null) : OperationDiagnostics::nodeFailure($state['error'] ?? null), 'updated_at' => now(),
                ]);
                if (! $success) {
                    DB::afterCommit(fn () => FailureAlerts::operation($this->requestUuid));
                }
                if ($record->operation === 'create' && ! $backup->completed_at) {
                    if ($success) {
                        RecoveryVault::snapshot($backup, $state['snapshot'], array_intersect_key($state, array_flip(['storage', 'repository'])));
                    }
                    $backup->update(['is_successful' => $success, 'is_locked' => $success ? $backup->is_locked : false,
                        'checksum' => null, 'bytes' => $success ? ($state['storage']['data_added_packed'] ?? $state['bytes']) : 0, 'completed_at' => now()]);
                    // A snapshot ID is not a file checksum. Do not invent archive checksums.
                    DB::afterCommit(fn () => event(new BackupCompleted($backup)));
                    if ($success) {
                        ApplyRetention::dispatch($record->server_uuid)->afterCommit();
                    }
                }
                if ($success && $record->operation === 'stats') {
                    foreach ($state['repository']['snapshots'] ?? [] as $uuid => $storage) {
                        Backup::where('uuid', $uuid)->where('backup_host_id', $record->host_id)
                            ->where('is_successful', true)
                            ->whereHas('server', fn ($q) => $q->where('uuid', $record->server_uuid)->where('node_id', $record->node_id))
                            ->update(['bytes' => $storage['data_added_packed']]);
                    }
                }
                if ($record->operation === 'restore') {
                    // Unknown or failed restores remain blocked for inspection; do not silently clear protection.
                    if ($success) {
                        $backup->server->update(['status' => null]);
                    }
                    Activity::event($success ? 'server:backup.restore-complete' : 'server:backup.restore-failed')->subject($backup, $backup->server)->log();
                } elseif ($record->operation === 'create') {
                    Activity::event($success ? 'server:backup.complete' : 'server:backup.fail')->subject($backup, $backup->server)->log();
                }
            });
        } finally {
            $lock->release();
        }
    }

    private function markUnknown(): void
    {
        DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'unknown', 'error' => 'Completion cannot be verified; reconcile before continuing.', 'updated_at' => now()]);
        DB::afterCommit(fn () => FailureAlerts::operation($this->requestUuid));
    }

    public function failed(?Throwable $exception): void
    {
        $this->markUnknown();
    }
}
