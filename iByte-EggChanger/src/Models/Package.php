<?php

namespace IByte\EggChanger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resource-gated package of administrator-approved eggs.
 *
 * Units are the panel's native allocated MiB (servers.memory / servers.disk cast
 * verified in the pinned Panel source). Zero minimum = no minimum for that
 * resource — NOT unlimited. Only finite server allocations qualify (see
 * Packages/Evaluator for the unlimited semantics).
 */
final class Package extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'name', 'description', 'min_memory_mb', 'min_disk_mb', 'sort_order', 'enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'min_memory_mb' => 'integer',
        'min_disk_mb' => 'integer',
        'sort_order' => 'integer',
        'enabled' => 'boolean',
    ];

    public function getTable(): string
    {
        return 'ibyte_egg_changer_packages';
    }
}