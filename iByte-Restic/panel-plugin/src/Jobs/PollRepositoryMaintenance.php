<?php

namespace IByte\Restic\Jobs;

use App\Models\Node;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\FailureAlerts;
use IByte\Restic\Support\OperationDiagnostics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PollRepositoryMaintenance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1000;

    public int $timeout = 45;

    public function __construct(public string $requestUuid) {}

    public function handle(Transport $transport): void
    {
        $lock = Cache::lock('ibyte-restic:retained:'.$this->requestUuid, 60);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $row = DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->first();
            if (! $row || ! in_array($row->status, ['queued', 'running'], true)) {
                return;
            }
            if (now()->diffInSeconds($row->created_at, absolute: true) > 7500) {
                $this->unknown();

                return;
            }
            Compatibility::assertSupported();
            $node = Node::whereKey($row->node_id)->where('scheme', 'https')->first();
            if (! $node) {
                $this->unknown();

                return;
            }
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            try {
                $ready = $transport->client($node)->timeout(10)->get('/api/restic/readiness')->throw()->json();
                if (($ready['version'] ?? null) !== '3.0.0-preview.2' || ($ready['retained_maintenance'] ?? null) !== 'v1' || ($ready['ready'] ?? false) !== true || ($ready['profiles'][$payload['profile']] ?? null) !== $payload['backend']) {
                    $this->unknown();

                    return;
                }
                $state = $transport->client($node)->timeout(15)->post('/api/restic/repository-maintenance', $payload)->throw()->json();
            } catch (Throwable) {
                $this->release(10);

                return;
            }
            foreach (['host', 'node', 'server', 'backup', 'profile', 'operation', 'request_id'] as $key) {
                if (($state[$key] ?? null) !== $payload[$key]) {
                    $this->unknown();

                    return;
                }
            }
            if (($state['generation'] ?? 0) !== $payload['generation'] || ! in_array($state['status'] ?? null, ['running', 'succeeded', 'failed'], true)
                || ($state['status'] === 'succeeded' && (! StorageReport::validMetrics($state) || ($row->operation === 'stats' && ! isset($state['repository']))))) {
                $this->unknown();

                return;
            }
            DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->whereIn('status', ['queued', 'running'])->update([
                'status' => $state['status'], 'metrics' => $state['status'] === 'succeeded' ? json_encode(array_intersect_key($state, array_flip(['repository'])), JSON_THROW_ON_ERROR) : null,
                'error' => $state['status'] === 'failed' ? OperationDiagnostics::nodeFailure($state['error'] ?? null) : (isset($state['maintenance_error']) ? 'Maintenance finished; storage measurement remains unavailable.' : null), 'updated_at' => now(),
            ]);
            if ($state['status'] === 'running') {
                $this->release(10);
            } elseif ($state['status'] === 'failed') {
                FailureAlerts::operation($this->requestUuid);
            }
        } finally {
            $lock->release();
        }
    }

    private function unknown(): void
    {
        DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'unknown', 'error' => 'Repository maintenance completion cannot be verified.', 'updated_at' => now()]);
        FailureAlerts::operation($this->requestUuid);
    }

    public function failed(?Throwable $exception): void
    {
        $this->unknown();
    }
}
