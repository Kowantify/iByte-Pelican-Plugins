<?php

namespace IByte\Restic\Jobs;

use App\Models\Server;
use App\Models\User;
use IByte\Restic\Recovery\ForcedRepositoryRemoval;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Support\FailureAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DestroyServerRepositories implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1000;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public string $requestUuid) {}

    public function uniqueId(): string
    {
        return $this->requestUuid;
    }

    public function handle(ForcedRepositoryRemoval $removal): void
    {
        $lock = Cache::lock('ibyte-restic:destroy-server-repositories:'.$this->requestUuid, 150);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            $row = DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->where('operation', 'lifecycle')->first();
            if (! $row || ! in_array($row->status, ['queued', 'running', 'unknown'], true)) {
                return;
            }
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['kind'] ?? null) !== 'server-destroy' || Server::where('uuid', $row->server_uuid)->exists()) {
                return;
            }
            $actor = User::findOrFail($payload['actor_id']);
            foreach ($payload['repositories'] as $target) {
                $repo = RecoveryRepository::findOrFail($target['id']);
                if (isset($repo->metadata['repository_deleted_at'])) {
                    continue;
                }
                $removal->resumeServerDestruction($actor, $repo, $this->requestUuid);
                // Bound each worker invocation to one physical repository. The
                // durable plan and scheduler recover after queue/node restarts.
                $remaining = collect($payload['repositories'])->contains(function ($entry) {
                    $candidate = RecoveryRepository::find($entry['id']);

                    return ! $candidate || ! isset($candidate->metadata['repository_deleted_at']);
                });
                if ($remaining) {
                    $this->release(10);

                    return;
                }
                break;
            }
            DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->update(['status' => 'succeeded', 'error' => null, 'updated_at' => now()]);
        } catch (Throwable) {
            // Never include raw HTTP responses or preserve a password in a job.
            DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->update(['status' => 'unknown', 'error' => 'Server deleted; original repository destruction remains pending. Check source node availability, safe path and active work. No physical success is claimed.', 'updated_at' => now()]);
            FailureAlerts::operation($this->requestUuid);
            $this->release(60);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('ibyte_restic_operations')->where('request_uuid', $this->requestUuid)->whereIn('status', ['queued', 'running', 'unknown'])
            ->update(['status' => 'unknown', 'error' => 'Server deleted; repository cleanup needs retry through the existing scheduler.', 'updated_at' => now()]);
        FailureAlerts::operation($this->requestUuid);
    }
}
