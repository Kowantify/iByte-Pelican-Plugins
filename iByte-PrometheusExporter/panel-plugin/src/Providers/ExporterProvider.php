<?php

namespace IByte\PrometheusExporter\Providers;

use IByte\PrometheusExporter\Console\Collect;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ExporterProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([Collect::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('ibyte:prometheus-collect')
                ->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();
        });
    }
}
