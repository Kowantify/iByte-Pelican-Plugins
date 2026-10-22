<?php

namespace IByte\EggChanger\Models;

use Illuminate\Database\Eloquent\Model;

/** Many-to-many package/egg membership. Deleting a package cascades only mappings. */
final class PackageEgg extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['package_id', 'egg_id'];

    /** @var array<string, string> */
    protected $casts = [
        'package_id' => 'integer',
        'egg_id' => 'integer',
    ];

    public function getTable(): string
    {
        return 'ibyte_egg_changer_package_eggs';
    }
}