<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Models;

use Illuminate\Database\Eloquent\Model;

final class Profile extends Model
{
    protected $table = 'imm_profiles';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['images' => 'array', 'enabled' => 'boolean'];
    }
}
