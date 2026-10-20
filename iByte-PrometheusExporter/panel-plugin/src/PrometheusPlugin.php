<?php

namespace IByte\PrometheusExporter;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class PrometheusPlugin implements Plugin
{
    public function getId(): string { return 'ibyte-prometheus-exporter'; }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->pages([Admin\ExporterPage::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
