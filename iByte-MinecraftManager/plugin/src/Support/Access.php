<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use App\Enums\PluginStatus;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use IByte\MinecraftManager\Models\Profile;
use Illuminate\Support\Facades\Context;

final class Access
{
    public function __construct(private readonly Settings $settings) {}

    public function profile(Server $server): ?Profile
    {
        if (!$this->settings->enabled()) {
            return null;
        }
        $profile = Profile::where('egg_id', $server->egg_id)->where('enabled', true)->first();

        return $profile;
    }

    public function allowed(?User $user, ?Server $server, string $action = 'browse'): bool
    {
        $server?->unsetRelation('subusers');
        if (!$user || !$server || !$this->profile($server) || !$user->canAccessTenant($server)) {
            return false;
        }
        // Re-query subuser permissions in delayed jobs and every action, rather than
        // relying on an already-loaded relationship from a previous render.
        $server->unsetRelation('subusers');
        // User::checkPermission caches in Laravel Context. Invalidate only this
        // source-confirmed key so revocation also holds inside long-lived work.
        Context::forget("users.{$user->id}.servers.{$server->id}.minecraft-manager.$action");

        return $user->can('minecraft-manager.' . $action, $server);
    }

    public function authorize(?User $user, ?Server $server, string $action = 'browse'): Profile
    {
        abort_unless($this->allowed($user, $server, $action), 403);

        return $this->profile($server);
    }

    public function pluginEnabled(): bool
    {
        return Plugin::find('ibyte-minecraft-manager')?->status === PluginStatus::Enabled;
    }

    public static function fingerprint(Server $server, Profile $profile): string
    {
        return hash('sha256', json_encode([
            $server->id, $server->uuid, $server->node_id, $server->egg_id,
            $server->image, $server->startup, $server->status?->value,
            $profile->id, $profile->updated_at?->toISOString(), $server->egg->docker_images,
            \IByte\MinecraftManager\Models\Setting::find('runtime-'.$server->id)?->value,
            RuntimeVariables::environment($server),
        ], JSON_THROW_ON_ERROR));
    }

    public static function runtime(Server $server, Profile $profile): array
    {
        $record = \IByte\MinecraftManager\Models\Setting::find('runtime-'.$server->id)?->value ?? [];
        if ($record && isset($record['environment'])) {
            $environment = RuntimeVariables::environment($server);
            foreach ($record['environment'] as $key=>$value) {
                if (($environment[$key] ?? null) !== $value) { $record = []; break; }
            }
        }
        if (!$record) { $record = RuntimeVariables::configured($server); }
        elseif (!isset($record['source'])) { $record['source'] = 'installed'; }
        $software = strtoupper($record['software'] ?? 'UNKNOWN');
        $type = match ($software) {
            'FABRIC', 'FORGE', 'NEOFORGE', 'QUILT' => 'mod',
            'PAPER', 'PURPUR', 'SPIGOT', 'BUKKIT', 'FOLIA' => 'plugin',
            'VELOCITY', 'BUNGEECORD', 'WATERFALL' => 'proxy',
            'VANILLA' => 'none', default => 'none',
        };
        return [...$record, 'software' => $software, 'content_type' => $type, 'loader' => $record['loader'] ?? '', 'version' => $record['version'] ?? 'unknown'];
    }
}
