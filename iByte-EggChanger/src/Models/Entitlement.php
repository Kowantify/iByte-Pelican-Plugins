<?php

namespace IByte\EggChanger\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Server;
use Illuminate\Support\Facades\Schema;

final class Entitlement extends Model
{
    protected $table = 'ibyte_egg_changer_entitlements';
    protected $guarded = [];
    protected $casts = ['enabled' => 'boolean'];

    public static function allowed(int $serverId): bool
    {
        if (!Schema::hasTable('ibyte_egg_changer_entitlements')) { return false; }
        $uuid = Server::query()->whereKey($serverId)->value('uuid');
        return $uuid !== null && static::query()->where('server_id', $serverId)
            ->where('server_uuid', (string) $uuid)->where('enabled', true)->exists();
    }
}
