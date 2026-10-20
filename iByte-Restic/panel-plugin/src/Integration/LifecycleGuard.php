<?php

namespace IByte\Restic\Integration;

use App\Models\Server;
use IByte\Restic\Backups\Retention;
use IByte\Restic\Recovery\ServerDestruction;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class LifecycleGuard
{
    public static function assertNotReserved(Server $server): void
    {
        if (! Schema::hasTable('ibyte_restic_operations')) {
            return;
        }
        if (DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where(fn ($query) => $query
            ->where(fn ($q) => $q->where('operation', 'lifecycle')->where('status', 'reserved'))
            ->orWhere(fn ($q) => $q->where('operation', 'repo-delete')->whereIn('status', ['reserved', 'queued', 'running', 'unknown'])))->exists()) {
            throw new ConflictHttpException('A confirmed Restic deletion/transfer is reserved. Complete or retry that administrator action before changing backups.');
        }
    }

    public static function assertClear(Server $server, bool $retaining = false): void
    {
        if (app(ServerDestruction::class)->active($server)) {
            return;
        }
        if (! Schema::hasTable('ibyte_restic_operations')) {
            if (Retention::applies($server) || $server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->exists()) {
                Installation::assertReady();
            }

            return;
        }
        if (! $retaining) {
            self::assertNotReserved($server);
        }
        if ((! $retaining && $server->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->exists())
            || DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where(fn ($query) => $query->whereIn('status', ['queued', 'running', 'unknown'])
                ->orWhere(fn ($q) => $q->where('operation', 'repo-delete')->where('status', 'reserved')))->exists()) {
            throw new ConflictHttpException('Restic blocks ordinary server deletion/transfer while backups or unresolved operations remain. Reconcile pending work. A root admin must confirm latest-only source retention with the native Delete or Transfer action. Repository transfer is unsupported.');
        }
    }
}
