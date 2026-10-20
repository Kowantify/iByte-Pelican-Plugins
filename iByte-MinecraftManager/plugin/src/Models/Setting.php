<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Models;

use Illuminate\Database\Eloquent\Model;

final class Setting extends Model
{
    protected $table = 'imm_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['value' => 'array', 'secret' => 'encrypted:array'];
    }
}
