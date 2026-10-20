<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with(config('database.connections.sqlite.database'), 'imm-ui-install.sqlite')) { throw new RuntimeException('Requires the disposable UI-install database copy.'); }
Illuminate\Support\Facades\DB::table('jobs')->delete();
// This fixture deliberately deletes a previously queued native install. Release
// that job's unique lock too; otherwise Laravel correctly refuses redispatch.
Illuminate\Support\Facades\Cache::lock('laravel_unique_job:App\\Jobs\\Plugin\\InstallPlugin:plugin:install:ibyte-minecraft-manager')->forceRelease();
Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '%minecraft_manager%')->orWhere('migration', 'like', '%expand_install_journals%')->delete();
foreach (['imm_inventory', 'imm_operations', 'imm_profiles', 'imm_settings'] as $table) { Illuminate\Support\Facades\Schema::dropIfExists($table); }
echo "Prepared disposable copied database for first plugin installation.\n";
