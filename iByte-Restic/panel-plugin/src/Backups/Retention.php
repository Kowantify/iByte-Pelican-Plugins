<?php

namespace IByte\Restic\Backups;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Retention
{
    public static function host(Server $server): ?BackupHost
    {
        // Exact native selection order; never select a different host for retention.
        return $server->node->backupHosts()->first() ?? BackupHost::doesntHave('nodes')->first();
    }

    public static function applies(Server $server): bool
    {
        return self::host($server)?->schema === 'restic';
    }

    public static function policy(Server $server): array
    {
        if (Installation::missing() !== []) {
            return ['enabled' => false, 'daily' => 0, 'weekly' => 0, 'timezone' => 'UTC', 'last_error' => 'Finish Restic installation / Repair tables in the admin panel.'];
        }
        $row = DB::table('ibyte_restic_retention')->where('server_uuid', $server->uuid)->first();

        return $row ? (array) $row : ['enabled' => false, 'daily' => 0, 'weekly' => 0, 'timezone' => 'UTC'];
    }

    public static function save(User $user, Server $server, array $data): void
    {
        abort_unless(self::applies($server) && $user->can(SubuserPermission::BackupDelete, $server)
            && $user->can(SubuserPermission::BackupCreate, $server), 403);
        Installation::assertReady();
        $data = Validator::make($data, [
            'enabled' => ['required', 'boolean'], 'daily' => ['required', 'integer', 'min:0', 'max:1000'],
            'weekly' => ['required', 'integer', 'min:0', 'max:1000'], 'timezone' => ['required', 'timezone'],
        ])->validate();
        Validator::make($data, ['daily' => [function ($attribute, $value, $fail) use ($data, $server) {
            if ($data['enabled'] && $value + $data['weekly'] >= $server->backup_limit) {
                $fail('Daily and weekly reservations must leave at least one slot for a new hourly backup.');
            }
        }]])->validate();
        DB::transaction(function () use ($user, $server, $data) {
            Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            DB::table('ibyte_restic_retention')->updateOrInsert(['server_uuid' => $server->uuid], $data + [
                'updated_by' => $user->id, 'updated_at' => now(), 'created_at' => now(), 'last_error' => null,
            ]);
            Activity::event('server:backup.retention-policy')->subject($server)->property($data)->log();
        });
        // Saving changes no snapshots. Apply after the next successful native backup.
    }

    /** Newest representatives of completed ISO weeks, completed days, then hours.
     * Locked/pending snapshots consume slots and are never automatic candidates.
     * Counts are total bucket counts, not age/deletion deadlines.
     */
    public static function keep(Collection $backups, array $policy, int $limit, ?CarbonImmutable $clock = null, bool $reserveIncoming = false): Collection
    {
        $ordered = $backups->sort(function ($a, $b) {
            return $b->created_at <=> $a->created_at ?: $b->id <=> $a->id;
        })->values();
        $keep = $ordered->filter(fn ($b) => $b->is_locked || ! $b->completed_at)->pluck('id');
        $budget = max(0, $limit - $keep->count());
        $candidates = $ordered->filter(fn ($b) => ! $keep->contains($b->id));
        if (! ($policy['enabled'] ?? false)) {
            return $keep->concat($candidates->take($budget)->pluck('id'));
        }
        $clock = ($clock ?? CarbonImmutable::now())->setTimezone($policy['timezone']);
        $selected = collect();
        // Preserve the newest usable point before allocating older tiers. When
        // making room at full quota the incoming backup occupies that slot.
        $newest = $ordered->first(fn ($b) => $b->is_successful && $b->completed_at);
        $latestHour = null;
        if (! $reserveIncoming && $newest) {
            $latestHour = $newest->created_at->setTimezone($policy['timezone'])->format('Y-m-d-H-P');
            if (! $keep->contains($newest->id) && $budget > 0) {
                $selected->push($newest->id);
                $budget--;
            }
        }
        foreach (['weekly', 'daily', 'hourly'] as $tier) {
            $count = $tier === 'hourly' ? $budget : min($budget, (int) $policy[$tier]);
            $buckets = $tier === 'hourly' && $latestHour !== null ? [$latestHour => true] : [];
            foreach ($candidates as $backup) {
                if ($count <= 0) {
                    break;
                }
                if ($selected->contains($backup->id)) {
                    continue;
                }
                $date = $backup->created_at->setTimezone($policy['timezone']);
                if (($tier === 'weekly' && $date >= $clock->startOfWeek()) || ($tier === 'daily' && $date >= $clock->startOfDay())) {
                    continue;
                }
                $bucket = $date->format(match ($tier) {
                    'weekly' => 'o-W', 'daily' => 'Y-m-d', default => 'Y-m-d-H-P',
                });
                if (isset($buckets[$bucket])) {
                    continue;
                }
                $buckets[$bucket] = true;
                $selected->push($backup->id);
                $count--;
                $budget--;
            }
        }

        return $keep->concat($selected);
    }

    public static function candidate(Server $server, bool $reserve = false): ?Backup
    {
        $backups = $server->backups()->nonFailed()->orderBy('created_at')->orderBy('id')->get();
        $keep = self::keep($backups, self::policy($server), max(0, $server->backup_limit - (int) $reserve), reserveIncoming: $reserve);

        // Never automatically delete a backup from a different provider.
        return $backups->first(fn ($b) => ! $keep->contains($b->id) && ! $b->is_locked
            && $b->completed_at && $b->is_successful && $b->backupHost->schema === 'restic');
    }
}
