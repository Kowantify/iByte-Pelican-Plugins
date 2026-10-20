<?php

declare(strict_types=1);

namespace IByte\MinecraftManager;

use App\Contracts\Plugins\HasPluginSettings;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use IByte\MinecraftManager\Support\Settings;

final class MinecraftManagerPlugin implements Plugin, HasPluginSettings
{
    public function getId(): string
    {
        return 'ibyte-minecraft-manager';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->pages([Filament\Server\MinecraftManager::class]);
        } elseif ($panel->getId() === 'admin') {
            $panel->pages([Filament\Admin\MinecraftManager::class]);
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);

        return ['enabled' => app(Settings::class)->enabled()];
    }

    public function getSettingsForm(): array
    {
        return [Toggle::make('enabled')->label(trans('ibyte-minecraft-manager::messages.enabled'))];
    }

    public function saveSettings(array $data): void
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        app(Settings::class)->save(['enabled' => (bool) ($data['enabled'] ?? false)]);
    }
}
