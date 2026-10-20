<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Software;

use App\Models\Egg;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Support\Input;

final class ProfileWizard
{
    public function save(array $data): Profile
    {
        $egg = Egg::findOrFail($data['egg_id']);
        return Profile::updateOrCreate(['egg_id' => $egg->id], [
            'name' => Input::text($egg->name, 100), 'software' => 'UNKNOWN',
            'images' => [], 'jar_path' => 'server.jar', 'version_variable' => null,
            'loader' => null, 'content_type' => 'none', 'enabled' => (bool) ($data['enabled'] ?? true),
        ]);
    }
}
