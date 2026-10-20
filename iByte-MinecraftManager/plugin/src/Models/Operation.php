<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Operation extends Model
{
    public const ACTIVE_STATUSES = ['queued', 'preflight', 'downloading', 'verifying', 'applying', 'verifying-result', 'cancelling', 'rolling-back'];

    protected $table = 'imm_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['idempotency', 'digest', 'server_slot'];

    /** Discard uncertain claims, including paths from pre-direct-install records. */
    public function forgetInventory(): void
    {
        $paths = array_unique([
            ...($this->plan['direct_files'] ?? []),
            ...array_keys($this->plan['installed_files'] ?? []),
            ...array_keys($this->plan['journal'] ?? []),
        ]);
        foreach (array_chunk($paths, 500) as $chunk) {
            DB::table('imm_inventory')->where('server_id', $this->server_id)->whereIn('path', $chunk)->delete();
        }
        if ($this->kind !== 'package') {
            Setting::where('key', 'runtime-'.$this->server_id)->delete();
        }
    }

    /** Failures and worker termination share one atomic slot-release policy. */
    public function failInstall(string $code, bool $cancelled = false, array $context = []): void
    {
        DB::transaction(function () use ($code, $cancelled, $context): void {
            $row = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (!in_array($row->status, self::ACTIVE_STATUSES, true) || $row->server_slot !== $row->server_id) {
                return;
            }
            $cancelled = $cancelled || $row->status === 'cancelling';
            $row->forgetInventory();
            $plan = $row->plan;
            $plan['partial_files_kept'] = true;
            if ($context) {
                $plan['failure'] = $context;
            }
            $row->update(['status' => $cancelled ? 'cancelled' : 'failed', 'step' => 'partial-files-kept',
                'error_code' => $cancelled ? 'install_cancelled' : $code, 'server_slot' => null, 'finished_at' => now(), 'plan' => $plan]);
        });
        $this->refresh();
    }

    public function displayTitle(): string
    {
        $release = $this->plan['release'] ?? [];
        $name = trim($release['project_title'] ?? '');
        $title = trim($release['title'] ?? $this->kind ?? 'Installation');
        if ($name === '' || stripos($title, $name) !== false) {
            return $title;
        }

        return $title === '' ? $name : $name.' · '.$title;
    }

    public function scopeForFeed(Builder $query): Builder
    {
        $query->select(['id', 'server_id', 'server_slot', 'actor_id', 'kind', 'provider', 'status', 'step',
            'error_code', 'created_at', 'updated_at', 'acknowledged_at']);
        // The journal is LONGTEXT. PostgreSQL JSON operators require an explicit cast.
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->selectRaw('CAST("selection" AS JSONB) ->> \'intent\' AS feed_intent, CAST("plan" AS JSONB) #>> \'{release,title}\' AS feed_title');
            $query->selectRaw('CAST("plan" AS JSONB) #>> \'{release,project_title}\' AS feed_project_title');
            foreach (['progress', 'failure', 'source_resolutions', 'skipped'] as $key) {
                $query->selectRaw('CAST("plan" AS JSONB) -> \''.$key.'\' AS feed_'.$key);
            }

            return $query;
        }

        return $query->addSelect(['selection->intent as feed_intent', 'plan->release->title as feed_title', 'plan->release->project_title as feed_project_title',
            'plan->progress as feed_progress', 'plan->failure as feed_failure',
            'plan->source_resolutions as feed_source_resolutions', 'plan->skipped as feed_skipped']);
    }

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer', 'server_id' => 'integer', 'node_id' => 'integer', 'server_slot' => 'integer',
            'selection' => 'array', 'plan' => 'array', 'expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
