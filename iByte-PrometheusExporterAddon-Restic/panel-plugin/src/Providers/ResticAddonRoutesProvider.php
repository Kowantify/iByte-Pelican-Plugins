<?php

namespace IByte\PrometheusExporterAddonRestic\Providers;

use IByte\PrometheusExporterAddonRestic\Http\Metrics;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

final class ResticAddonRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(static function (): void {
            Route::get('/ibyte-prometheus-exporter-addon-restic/metrics', Metrics::class)
                ->name('ibyte-prometheus-exporter-addon-restic.metrics');
        });
    }
}