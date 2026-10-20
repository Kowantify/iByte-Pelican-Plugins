<?php

namespace IByte\EggChanger;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class EggChangerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ibyte-egg-changer';
    }

    public function register(Panel $panel): void
    {
        // Admin: one Egg Changer page with Packages, Operations and Settings tabs.
        if ($panel->getId() === 'admin') {
            $panel->pages([Filament\Admin\ManageEggChanger::class]);
        }

        // Server: one Egg Changer page in the client server area.
        if ($panel->getId() === 'server') {
            $panel->pages([Filament\Server\EggChanger::class]);
        }
    }

    public function boot(Panel $panel): void {}
}