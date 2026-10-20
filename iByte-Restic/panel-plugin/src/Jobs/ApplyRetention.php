<?php

namespace IByte\Restic\Jobs;

use App\Facades\Activity;
use App\Models\Server;
use App\Services\Backups\DeleteBackupService;
use IByte\Restic\Backups\Retention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApplyRetention implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public int $timeout = 45;

    public int $backoff = 30;

    public function __construct(public string $serverUuid) {}

    public function handle(): void
    {
        try {
            DB::transaction(function () {
                $server = Server::where('uuid', $this->serverUuid)->lockForUpdate()->first();
                if (! $server || ! Retention::applies($server)) {
                    return;
                }
                if ($server->backups()->whereNull('completed_at')->exists()) {
                    // A later create has started since this cleanup was queued.
                    // Its verified completion will schedule another cleanup.
                    return;
                }
                // One bounded forget per queue turn. Recompute using current locks,
                // quota, timezone and policy on every retry; no stale delete lists.
                $candidate = Retention::candidate($server);
                if ($candidate) {
                    app(DeleteBackupService::class)->handle($candidate);
                    Activity::event('server:backup.retention-delete')->subject($server)->property(['backup_uuid' => $candidate->uuid, 'reason' => 'calendar retention'])->log();
                    self::dispatch($this->serverUuid)->delay(now()->addSeconds(5))->afterCommit();
                }
                DB::table('ibyte_restic_retention')->where('server_uuid', $this->serverUuid)->update(['last_run_at' => now(), 'last_error' => null]);
            });
        } catch (Throwable $exception) {
            DB::table('ibyte_restic_retention')->where('server_uuid', $this->serverUuid)->update(['last_error' => 'Retention could not confirm snapshot removal. Queue retry/reconciliation required.']);
            throw $exception;
        }
    }
}
