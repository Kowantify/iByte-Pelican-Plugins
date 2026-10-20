<?php

namespace IByte\PrometheusExporter\Providers;

use IByte\PrometheusExporter\Http\Metrics;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

final class RoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(static function (): void {
            Route::get('/ibyte-prometheus-exporter/metrics', Metrics::class)
                ->name('ibyte-prometheus-exporter.metrics');
        });
    }
}
