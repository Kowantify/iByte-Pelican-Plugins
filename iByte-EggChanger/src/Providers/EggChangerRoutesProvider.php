<?php

namespace IByte\EggChanger\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use IByte\EggChanger\Http\Controllers\CompatibilityController;
use IByte\EggChanger\Http\Controllers\EntitlementController;

/** Plugin route, authenticated by the panel's existing Application API key. */
final class EggChangerRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware(['api', 'application-api', 'throttle:api.application'])
                ->prefix('/api/application/ibyte-egg-changer')
                ->group(function () {
                    Route::put('/servers/{serverId}/entitlement', [EntitlementController::class, 'update'])
                        ->whereNumber('serverId')->name('ibyte-egg-changer.entitlement.update');
                    Route::get('/compatibility', CompatibilityController::class)
                        ->name('ibyte-egg-changer.compatibility');
                });
        });
    }
}
