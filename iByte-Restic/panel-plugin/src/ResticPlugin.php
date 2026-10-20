<?php

namespace IByte\Restic;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class ResticPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ibyte-restic';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->resources([Admin\RecoveryResource::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
