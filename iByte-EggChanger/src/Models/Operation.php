<?php

namespace IByte\EggChanger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable journal row for one clean-switch operation.
 *
 * Phases: queued → backup → cleaning → configuring → reinstalling
 *         → completed | failed | needs_reconciliation.
 * No secrets are ever stored here (no variable values, no node tokens).
 */
final class Operation extends Model
{
    public const PHASE_QUEUED = 'queued';

    public const PHASE_BACKUP = 'backup';

    public const PHASE_CLEANING = 'cleaning';

    public const PHASE_CONFIGURING = 'configuring';

    public const PHASE_REINSTALLING = 'reinstalling';

    public const PHASE_COMPLETED = 'completed';

    public const PHASE_FAILED = 'failed';

    public const PHASE_RECONCILE = 'needs_reconciliation';

    /** @var array<int, string> */
    protected $fillable = [
        'uuid', 'server_id', 'server_uuid', 'actor_id',
        'source_egg_id', 'target_egg_id', 'package_id',
        'snapshot_memory_mb', 'snapshot_disk_mb',
        'snapshot_min_memory_mb', 'snapshot_min_disk_mb',
        'phase', 'failure_stage', 'failure_message',
        'backup_uuid', 'backup_successful',
        'queued_at', 'finished_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'server_id' => 'integer',
        'actor_id' => 'integer',
        'source_egg_id' => 'integer',
        'target_egg_id' => 'integer',
        'package_id' => 'integer',
        'snapshot_memory_mb' => 'integer',
        'snapshot_disk_mb' => 'integer',
        'snapshot_min_memory_mb' => 'integer',
        'snapshot_min_disk_mb' => 'integer',
        'backup_successful' => 'boolean',
        'queued_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return 'ibyte_egg_changer_operations';
    }
}