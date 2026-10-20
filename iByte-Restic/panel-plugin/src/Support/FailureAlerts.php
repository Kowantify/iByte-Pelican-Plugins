<?php

namespace IByte\Restic\Support;

use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

final class FailureAlerts
{
    public static function operation(string $request): void
    {
        // During native install, old workers must not query a pending column.
        if (! Schema::hasColumn('ibyte_restic_operations', 'alerts_delivered_at')) {
            return;
        }
        DB::transaction(function () use ($request) {
            $row = DB::table('ibyte_restic_operations')->where('request_uuid', $request)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['failed', 'unknown'], true)) {
                return;
            }
            if ($row->alerts_delivered_at !== null) {
                return;
            }
            // Never disclose arbitrary node errors, names, payloads or credentials.
            $body = 'Node '.$row->node_id.', server '.$row->server_uuid.'. '.self::message($row->status, $row->operation).' Operation ID: '.$row->request_uuid;
            $data = Notification::make()->danger()->title('Restic '.self::label($row->operation).' needs attention')->body($body)
                ->actions([Action::make('review')->label('Review in Restic Backups')->url(url('/admin/restic-backups/administrative'))])->getDatabaseMessage();
            $delivered = false;
            User::whereHas('roles', fn ($query) => $query->where('name', Role::ROOT_ADMIN)->where('guard_name', 'web'))->select('users.id')->chunkById(100, function ($admins) use ($row, $data, &$delivered) {
                foreach ($admins as $admin) {
                    // Persistent deterministic ID makes worker replay, scheduler
                    // catch-up and concurrent notification attempts idempotent.
                    $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'ibyte-restic:'.$row->request_uuid.':'.$row->status.':'.$admin->id)->toString();
                    $notice = $admin->notifications()->firstOrCreate(['id' => $id], ['type' => DatabaseNotification::class, 'data' => $data]);
                    if ($notice->data !== $data) {
                        // Upgrade the old generic label without resetting its
                        // original date or the administrator's read marker.
                        $notice->update(['data' => $data]);
                    }
                    $delivered = true;
                }
            });
            if ($delivered) {
                DB::table('ibyte_restic_operations')->where('request_uuid', $request)->update(['alerts_delivered_at' => now()]);
            }
        });
    }

    public static function dispatch(): void
    {
        if (! Schema::hasColumn('ibyte_restic_operations', 'alerts_delivered_at')) {
            return;
        }
        DB::table('ibyte_restic_operations')->whereIn('status', ['failed', 'unknown'])->where('updated_at', '>=', now()->subDays(7))
            ->whereNull('alerts_delivered_at')
            ->latest('updated_at')->limit(100)->pluck('request_uuid')->each(fn ($request) => self::operation($request));
    }

    public static function message(string $status, ?string $operation = null): string
    {
        if ($operation === 'lifecycle') {
            return 'Server deletion or repository cleanup could not be fully verified. Review the recorded lifecycle action and original node. Physical repository deletion is not yet confirmed.';
        }

        return $status === 'unknown' ? 'Completion could not be verified. Keep restore protection in place and review protected node state before retrying.'
            : 'The operation failed. Existing recovery points are not automatically removed to resolve this failure. Review the Panel and node logs.';
    }

    public static function recent(): array
    {
        return DB::table('ibyte_restic_operations')->whereIn('status', ['failed', 'unknown'])->latest('updated_at')->limit(25)
            ->get(['request_uuid', 'server_uuid', 'node_id', 'operation', 'status', 'updated_at', 'error'])
            ->map(function ($row) {
                $details = OperationDiagnostics::storedFailure($row->error);
                unset($row->error);

                return (array) $row + ['message' => self::message($row->status, $row->operation), 'details' => $details];
            })->all();
    }

    private static function label(string $operation): string
    {
        return match ($operation) {
            'check' => 'integrity check', 'stats' => 'storage measurement', 'prune' => 'storage cleanup',
            'create' => 'backup', 'restore' => 'restore', 'lifecycle' => 'server/repository deletion', default => 'operation',
        };
    }
}
