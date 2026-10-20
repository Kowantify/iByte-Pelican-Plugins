<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Console;

use Illuminate\Console\Command;
use IByte\MinecraftManager\Models\Setting;
use IByte\MinecraftManager\Support\Settings;

final class WorkInstalls extends Command
{
    protected $signature = 'minecraft-manager:work {--once} {--stop-when-empty}';
    protected $description = 'Run Minecraft Manager installs with an isolated long-running queue reservation';

    public function handle(): int
    {
        if (!app(Settings::class)->installed() || !config('queue.connections.minecraft-manager')) {
            $this->error('Minecraft Manager needs its installed schema and a database or Redis queue.');
            return self::FAILURE;
        }
        Setting::updateOrCreate(['key' => 'install-worker'], ['value' => ['last_started_at' => now()->toIso8601String()]]);
        return $this->call('queue:work', ['connection' => 'minecraft-manager', '--queue' => 'minecraft-manager',
            '--timeout' => 1800, '--tries' => 1, '--sleep' => 3, '--max-time' => 1740,
            '--once' => $this->option('once'), '--stop-when-empty' => $this->option('stop-when-empty')]);
    }
}
