<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with(config('database.connections.sqlite.database'), 'imm-ui-install.sqlite')) { throw new RuntimeException('Requires disposable UI-install database.'); }
$passed = 0;
foreach (['imm_settings', 'imm_profiles', 'imm_operations', 'imm_inventory'] as $table) {
    if (!Illuminate\Support\Facades\Schema::hasTable($table)) { throw new RuntimeException('UI install did not create '.$table); }
    echo 'PASS UI native installation created ', $table, "\n"; ++$passed;
}
if (App\Models\Plugin::findOrFail('ibyte-minecraft-manager')->status !== App\Enums\PluginStatus::Enabled) { throw new RuntimeException('Plugin is not enabled.'); }
$migrations = Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '%minecraft_manager%')->orWhere('migration', 'like', '%expand_install_journals%')->count();
if ($migrations !== 2) { throw new RuntimeException('Both migrations did not run.'); }
$manifest = json_decode(file_get_contents($root.'/.research/panel/public/build/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($manifest as $entry) {
    foreach ([$entry['file'], ...($entry['css'] ?? [])] as $asset) {
        if (!is_file($root.'/.research/panel/public/build/'.$asset)) { throw new RuntimeException('Missing built asset.'); }
    }
}
echo "PASS native UI enabled plugin, recorded both migrations and generated manifest with existing asset files\n";
++ $passed;
file_put_contents($root.'/docs/evidence/native-ui-install-probe.json', json_encode(['passed' => $passed, 'migrations' => $migrations, 'asset_manifest_entries' => count($manifest), 'source' => 'native UI and real native queue/build; isolated copied SQLite database'], JSON_PRETTY_PRINT)."\n");
