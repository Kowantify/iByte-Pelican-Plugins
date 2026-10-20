<?php

namespace IByte\PrometheusExporterAddonRestic;

use IByte\PrometheusExporterAddonRestic\Providers\ResticAddonProvider;
use IByte\PrometheusExporterAddonRestic\Providers\ResticAddonRoutesProvider;
use Illuminate\Support\ServiceProvider;

final class AddonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(ResticAddonProvider::class);
        $this->app->register(ResticAddonRoutesProvider::class);
    }
}