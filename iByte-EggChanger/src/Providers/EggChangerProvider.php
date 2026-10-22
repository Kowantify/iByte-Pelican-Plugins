<?php

namespace IByte\EggChanger\Providers;

use Illuminate\Support\ServiceProvider;

final class EggChangerProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Queued switch jobs are executed by the panel's existing queue worker
        // (verified: pelican-queue.service runs `php artisan queue:work` on this
        // deployment, QUEUE_CONNECTION=redis). Nothing else to schedule for v1.
    }
}