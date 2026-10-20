<?php

namespace IByte\PrometheusExporterAddonRestic;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class ResticAddonPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ibyte-prometheus-exporter-addon-restic';
    }

    // No admin page by design: the base exporter page remains the only place
    // a scrape key is created. This class exists so Pelican can load the
    // plugin and boot its providers.
    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}