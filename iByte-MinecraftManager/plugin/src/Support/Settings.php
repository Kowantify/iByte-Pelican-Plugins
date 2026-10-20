<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use IByte\MinecraftManager\Models\Setting;
use Illuminate\Support\Facades\Schema;

final class Settings
{
    public function installed(): bool
    {
        return Schema::hasTable('imm_settings') && Schema::hasTable('imm_profiles')
            && Schema::hasTable('imm_operations') && Schema::hasTable('imm_inventory');
    }

    public function values(): array
    {
        return $this->installed() ? (Setting::find('policy')?->value ?? []) : [];
    }

    public function enabled(): bool
    {
        return (bool) ($this->values()['enabled'] ?? false);
    }

    public function save(array $input): void
    {
        $allowed = array_intersect_key($input, array_flip(['enabled', 'experimental', 'providers']));
        Setting::updateOrCreate(['key' => 'policy'], ['value' => array_replace($this->values(), $allowed)]);
    }

    public function providerEnabled(string $provider): bool
    {
        return in_array($provider, $this->values()['providers'] ?? ['mcjars', 'modrinth', 'curseforge'], true);
    }

    public function credential(string $provider): ?string
    {
        return $this->installed() ? (Setting::find('credentials')?->secret[$provider] ?? null) : null;
    }

    public function replaceCredential(string $provider, ?string $key): void
    {
        if ($provider !== 'curseforge' || ($key !== null && (strlen($key) > 512 || strlen($key) < 8 || preg_match('/[\x00-\x20\x7f]/', $key)))) {
            throw new Failure('invalid_credentials');
        }
        $row = Setting::firstOrNew(['key' => 'credentials']);
        $secrets = $row->secret ?? [];
        unset($secrets[$provider]);
        if ($key !== null) {
            $secrets[$provider] = $key;
        }
        $row->secret = $secrets;
        $row->save();
        // No config/env cache and no credential-bearing shared response cache.
    }
}
