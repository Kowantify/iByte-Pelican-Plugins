<?php

namespace IByte\Restic\Support;

use App\Models\Backup;
use IByte\Restic\Jobs\CaptureRecovery;
use IByte\Restic\Recovery\RetainedMaintenance;
use IByte\Restic\Recovery\ServerDestruction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

final class Maintenance
{
    public static function register(Schedule $schedule): void
    {
        // Uses Pelican's existing scheduler; it never starts backups or a second cron.
        $schedule->call(fn () => self::dispatch())->everyFiveMinutes()
            ->name('ibyte-restic-repository-maintenance')->withoutOverlapping(10);
    }

    public static function dispatch(): void
    {
        if (in_array(config('queue.default'), ['sync', 'null'], true) || Installation::missing() !== []) {
            return;
        }
        FailureAlerts::dispatch();
        ServerDestruction::dispatchPending();
        RetainedMaintenance::automatic();
        $ids = Backup::query()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))
            ->whereHas('server')->selectRaw('MAX(id) AS id')->groupBy('server_id', 'backup_host_id')->pluck('id');
        foreach ($ids as $id) {
            $backup = Backup::find($id);
            if (! $backup?->server || ! $backup->backupHost) {
                continue;
            }
            $recent = DB::table('ibyte_restic_repositories')->where('host_id', $backup->backup_host_id)
                ->where('node_id', $backup->server->node_id)->where('server_uuid', $backup->server->uuid)
                ->where('captured_at', '>', now()->subHour())->exists();
            $measured = DB::table('ibyte_restic_operations')->where('server_uuid', $backup->server->uuid)
                ->where('host_id', $backup->backup_host_id)->where('node_id', $backup->server->node_id)
                ->where('status', 'succeeded')->where('metrics', 'like', '%"repository"%')
                ->where('updated_at', '>', now()->subHour())->exists();
            if (! $recent || ! $measured) {
                CaptureRecovery::dispatch($backup->uuid)->afterCommit();
            }
        }
    }
}
