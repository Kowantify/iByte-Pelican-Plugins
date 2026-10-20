<?php

namespace IByte\Restic\Recovery;

use Illuminate\Database\Eloquent\Model;

final class RecoveryRepository extends Model
{
    protected $table = 'ibyte_restic_repositories';

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'owner_history' => 'array', 'captured_at' => 'datetime', 'server_deleted_at' => 'datetime'];
    }
}
