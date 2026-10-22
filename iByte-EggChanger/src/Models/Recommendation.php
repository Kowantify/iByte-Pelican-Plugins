<?php

namespace IByte\EggChanger\Models;

use Illuminate\Database\Eloquent\Model;

/** Optional admin-authored workload guidance, never an eligibility gate. */
final class Recommendation extends Model
{
    protected $table = 'ibyte_egg_changer_recommendations';

    protected $fillable = [
        'egg_id', 'name', 'description', 'recommended_memory_mb',
        'recommended_disk_mb', 'sort_order',
    ];

    protected $casts = [
        'egg_id' => 'integer',
        'recommended_memory_mb' => 'integer',
        'recommended_disk_mb' => 'integer',
        'sort_order' => 'integer',
    ];
}
