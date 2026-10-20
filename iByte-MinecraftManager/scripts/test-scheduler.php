<?php

declare(strict_types=1);

$root = dirname(__DIR__);
putenv('DB_DATABASE=imm-scheduler.sqlite');
putenv('IMM_TEST_SCHEDULER=1');
copy($root.'/.research/panel/database/imm-preview.sqlite', $root.'/.research/panel/database/imm-scheduler.sqlite');
$providersPath = $root.'/.research/panel/bootstrap/providers.php';
$originalProviders = file_get_contents($providersPath);
$testProvider = "    ...(getenv('IMM_TEST_SCHEDULER') === '1' ? [require __DIR__.'/../../../scripts/scheduler-fixture.php'] : []),\n";
file_put_contents($providersPath, str_replace('return [', 'return ['."\n".$testProvider, $originalProviders));
$passed = 0;
function schedulerCheck(bool $result, string $name): void
{
    global $passed;
    if (!$result) { throw new RuntimeException($name); }
    ++$passed; echo 'PASS ', $name, "\n";
}
try {
    require $root.'/.research/panel/vendor/autoload.php';
    $app = require $root.'/.research/panel/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::table('jobs')->delete();
    IByte\MinecraftManager\Models\Operation::query()->delete();
    app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled' => true, 'experimental' => false, 'providers' => ['modrinth']]);
    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
    $events = array_values(array_filter($schedule->events(), fn ($event) => $event->description === 'minecraft-manager-installs'));
    schedulerCheck(count($events) === 1 && $events[0]->expression === '* * * * *' && $events[0]->runInBackground && $events[0]->withoutOverlapping,
        'one minute background task is registered on the existing native scheduler');
    $event = $events[0];
    schedulerCheck(!$event->filtersPass($app), 'idle scheduler does not start an empty install worker');
    $owner = App\Models\User::factory()->create();
    $egg = App\Models\Egg::factory()->create(['docker_images' => ['Java 21' => 'fixture:java_21']]);
    $server = App\Models\Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'fixture:java_21']);
    app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id' => $egg->id, 'enabled' => true]);
    $reviews = app(IByte\MinecraftManager\Operations\ReviewService::class);
    $selection = ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'scheduler', 'release' => 'scheduler-v1', 'version' => '1.21.1', 'loader' => 'fabric', 'type' => 'mod'];
    $op = $reviews->create($owner, $server, $selection);
    $reviews->queue($owner, $server, $op->id, true);
    schedulerCheck($op->fresh()->status === 'queued' && $event->filtersPass($app)
        && Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'minecraft-manager')->count() === 1,
        'confirmed installation wakes the scheduled dedicated queue without an extra service');
    Illuminate\Support\Facades\Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
    schedulerCheck($op->fresh()->status === 'queued',
        'reproduced original failure: the normal Panel worker leaves the separate install queue untouched');
    app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled' => false]);
    schedulerCheck(!$event->filtersPass($app), 'global disable prevents new scheduled workers during maintenance');
    app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled' => true]);
    $event->run($app);
    for ($i = 0; $i < 160 && in_array($op->fresh()->status, ['queued','preflight','downloading','verifying','applying','verifying-result'], true); $i++) { usleep(100000); }
    schedulerCheck($op->fresh()->status === 'succeeded' && $op->fresh()->step === 'installed',
        'actual native background scheduler worker completes the serialized installation: '.($op->fresh()->error_code ?? $op->fresh()->status));
    schedulerCheck(file_get_contents($root.'/.build/scheduler-node/mods/scheduler-test.jar') === 'scheduler-test-artifact'
        && Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id', $server->id)->where('path', 'mods/scheduler-test.jar')->exists(),
        'background worker verifies installed bytes and persists inventory in the isolated node fixture');
    schedulerCheck(IByte\MinecraftManager\Models\Setting::find('install-worker')?->value['last_started_at'] !== null
        && $op->fresh()->server_slot === null, 'worker start is recorded and completed installation releases its server');
    // A metadata-only request is consumed by the same worker, never called manually.
    $selection['release'] = 'scheduler-v1';
    $review = $reviews->create($owner, $server, $selection);
    $reviews->queue($owner, $server, $review->id);
    Illuminate\Support\Facades\Artisan::call('minecraft-manager:work', ['--stop-when-empty' => true]);
    schedulerCheck($review->fresh()->step === 'metadata-checked' && $review->fresh()->server_slot === null,
        'metadata checks share the drained queue and remain separate from installation');
    schedulerCheck(config('queue.connections.minecraft-manager.retry_after') === 1860,
        'native short queue reservation is not used for long installs');
    $cancelled = $reviews->create($owner, $server, $selection);
    $reviews->queue($owner, $server, $cancelled->id, true);
    $reviews->cancel($owner, $server, $cancelled->id);
    Illuminate\Support\Facades\Artisan::call('minecraft-manager:work', ['--stop-when-empty' => true]);
    schedulerCheck($cancelled->fresh()->status === 'cancelled' && $cancelled->fresh()->server_slot === null,
        'a queued cancellation remains cancelled when the real worker receives its job');
    $expired = $reviews->create($owner, $server, $selection);
    $reviews->queue($owner, $server, $expired->id, true);
    $expired->update(['expires_at' => now()->subMinute()]);
    Illuminate\Support\Facades\Artisan::call('minecraft-manager:work', ['--stop-when-empty' => true]);
    schedulerCheck($expired->fresh()->error_code === 'stale_plan' && $expired->fresh()->server_slot === null,
        'old queued installs are blocked and released instead of applying an expired review');
} finally {
    file_put_contents($providersPath, $originalProviders);
    file_put_contents($root.'/docs/evidence/scheduler.json', json_encode(['passed' => $passed,
        'scope' => 'Actual native scheduler background command and fresh PHP queue worker; copied Windows/SQLite database; deterministic provider/artifact and filesystem node fixture; no live Wings/game startup'], JSON_PRETTY_PRINT)."\n");
}
echo "RESULT $passed native scheduler checks passed.\n";
