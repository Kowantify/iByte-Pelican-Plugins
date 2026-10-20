<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Providers;

use App\Models\Subuser;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Console\WorkInstalls;
use IByte\MinecraftManager\Livewire\ActivityFeed;
use IByte\MinecraftManager\Livewire\ProjectInstall;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\SafeHttp;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class MinecraftManagerProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('imm-activity', ActivityFeed::class);
        Livewire::component('imm-project-install', ProjectInstall::class);
        // Separate queue: native short retry reservations must never overlap an install.
        $base = config('queue.connections.'.config('queue.default'));
        if (is_array($base) && in_array($base['driver'] ?? '', ['redis', 'database'], true)) {
            config(['queue.connections.minecraft-manager' => array_replace($base, ['queue' => 'minecraft-manager', 'retry_after' => 1860])]);
        }
        if ($this->app->runningInConsole()) {
            $this->commands([WorkInstalls::class]);
            // Use the Panel's existing minute scheduler. No separate service or
            // change to the native worker's short retry reservation is needed.
            $this->app->afterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('minecraft-manager:work', ['--stop-when-empty'])
                    ->name('minecraft-manager-installs')->everyMinute()->withoutOverlapping(35)->runInBackground()
                    ->appendOutputTo(storage_path('logs/minecraft-manager-worker.log'))
                    ->when(static fn (): bool => app(Settings::class)->installed() && app(Settings::class)->enabled() && app(Access::class)->pluginEnabled()
                        && Operation::where('status', 'queued')->exists());
            });
        }
    }

    public function register(): void
    {
        $this->app->bind(JsonTransport::class, SafeHttp::class);
        $this->app->bind(Catalog::class);
        Subuser::registerCustomPermissions('minecraft-manager', [
            'browse', 'plan', 'packages', 'software', 'packs', 'recover',
        ], 'ibyte-minecraft-manager::permissions', 'tabler-brand-minecraft', false);
    }
}
