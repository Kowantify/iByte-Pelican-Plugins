<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use App\Models\Server;
use Illuminate\Support\Facades\Validator;

/** Server-local values only; egg definitions and unrelated settings stay intact. */
final class RuntimeVariables
{
    private const TYPES = ['VANILLA', 'FABRIC', 'FORGE', 'NEOFORGE', 'QUILT', 'PAPER', 'PURPUR', 'SPIGOT', 'BUKKIT', 'FOLIA', 'VELOCITY', 'BUNGEECORD', 'WATERFALL'];
    private const KEYS = ['SERVER_TYPE', 'SERVER_SOFTWARE', 'VERSION', 'MINECRAFT_VERSION', 'MC_VERSION', 'BUILDS', 'BUILD_NUMBER', 'BUILD_VERSION', 'BUILD', 'SERVER_JARFILE', 'JARFILE', 'FORGE_VERSION', 'NEOFORGE_VERSION', 'FABRIC_VERSION', 'QUILT_VERSION'];

    public static function environment(Server $server): array
    {
        return $server->variables()->get()->mapWithKeys(static fn ($variable) => [$variable->env_variable => (string) ($variable->server_value ?? $variable->default_value ?? '')])->all();
    }

    public static function snapshot(Server $server): array
    {
        return array_intersect_key(self::environment($server), array_flip(self::KEYS));
    }

    public static function configured(Server $server): array
    {
        $environment = self::environment($server);
        $type = strtoupper($environment['SERVER_TYPE'] ?? $environment['SERVER_SOFTWARE'] ?? '');
        if ($type === 'CRAFTBUKKIT') { $type = 'BUKKIT'; }
        $version = $environment['MINECRAFT_VERSION'] ?? $environment['MC_VERSION'] ?? $environment['VERSION'] ?? '';
        if (!in_array($type, self::TYPES, true) || !preg_match('/\A(?:\d+(?:\.\d+){1,3}(?:[-_][A-Za-z0-9.]+)?|\d{2}w\d{2}[a-z])\z/D', $version)) { return []; }
        return ['software'=>$type, 'loader'=>strtolower($type), 'version'=>$version, 'source'=>'configured'];
    }

    public static function updates(Server $server, array $runtime): array
    {
        $type = strtoupper($runtime['software']);
        $build = $runtime['build_version'] ?? '';
        $values = ['SERVER_TYPE'=>strtolower($type), 'SERVER_SOFTWARE'=>strtolower($type),
            'VERSION'=>$runtime['version'], 'MINECRAFT_VERSION'=>$runtime['version'], 'MC_VERSION'=>$runtime['version'],
            'SERVER_JARFILE'=>'server.jar', 'JARFILE'=>'server.jar'];
        if ($build !== '') {
            foreach (['BUILDS', 'BUILD_NUMBER', 'BUILD_VERSION', 'BUILD'] as $key) { $values[$key] = $build; }
            if (in_array($type, ['FABRIC', 'QUILT', 'FORGE', 'NEOFORGE'], true)) { $values[$type.'_VERSION'] = $build; }
        }
        $updates = [];
        foreach ($server->variables()->get() as $variable) {
            $key = $variable->env_variable;
            if ($build === '' && in_array($key, ['BUILDS','BUILD_NUMBER','BUILD_VERSION','BUILD'], true)) { throw new Failure('provider_schema_changed', ['field'=>'build_version']); }
            if (!array_key_exists($key, $values)) { continue; }
            $value = $values[$key];
            // Keep native egg validation authoritative, including enum casing.
            $candidates = in_array($key, ['SERVER_TYPE', 'SERVER_SOFTWARE'], true) ? [$value, strtoupper($value)] : [$value];
            $valid = false;
            foreach ($candidates as $candidate) {
                if (Validator::make(['value'=>$candidate], ['value'=>$variable->rules])->passes()) { $value = $candidate; $valid = true; break; }
            }
            if (!$valid) { throw new Failure('runtime_variables_invalid', ['variable'=>$key, 'value'=>$value]); }
            $updates[$key] = $value;
        }
        return $updates;
    }

    public static function matches(Server $server, array $state): bool
    {
        $current = self::environment($server);
        foreach ($state['environment'] ?? [] as $key=>$value) { if (($current[$key] ?? null) !== $value) { return false; } }
        return $server->startup === $state['startup'] && $server->image === $state['docker_image'] && (bool) $server->skip_scripts === (bool) $state['skip_scripts'];
    }

    public static function forNativeUpdate(Server $server, array $state): array
    {
        if (!empty($state['environment'])) { $state['environment'] = array_replace(self::environment($server), $state['environment']); }
        return $state;
    }

    public static function startup(Server $server, array $runtime): string
    {
        $proxy = in_array(strtoupper($runtime['software']), ['VELOCITY', 'BUNGEECORD', 'WATERFALL'], true);
        $environment = self::environment($server); $known = true;
        foreach (['SERVER_JARFILE','JARFILE'] as $key) { if (str_contains($server->startup, '{{'.$key.'}}') && !array_key_exists($key, $environment)) { $known = false; } }
        if (!$proxy && $known && preg_match('~^\s*java\b[^\r\n]*\s-jar\s+(?:"server\.jar"|\x27server\.jar\x27|server\.jar|"?\{\{(?:SERVER_JARFILE|JARFILE)\}\}"?)(?:\s|$)~D', $server->startup)) { return $server->startup; }
        return 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar "server.jar"'.($proxy ? '' : ' nogui');
    }
}
