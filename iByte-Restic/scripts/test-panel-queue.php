<?php

// Fresh native CLI boots + a real SQLite database queue. No operator Panel.
declare(strict_types=1);

use App\Events\Server\BackupCompleted;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\ServerDestruction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
require $root.'/scripts/fixtures/recovery-response.php';
$panel = $root.'/.upstream/panel';
if (! is_file($panel.'/vendor/autoload.php') || ! is_file($panel.'/plugins/ibyte-restic/plugin.json')) {
    throw new RuntimeException('Run package-panel-plugin.php and test-panel.php against the disposable checkout first.');
}
require $panel.'/vendor/autoload.php';
$phase = $argv[1] ?? null;
if ($phase === null) {
    $database = $root.'/.build/panel-queue-'.bin2hex(random_bytes(8)).'.sqlite';
    fclose(fopen($database, 'x'));
    foreach (['produce', 'consume'] as $stage) {
        $child = new Process([PHP_BINARY, __FILE__, $stage, $database], $root);
        $child->setTimeout(90);
        $child->mustRun();
        echo $child->getOutput();
    }
    echo "Fresh-process native plugin loader + serialized database queue roundtrip passed.\n";
    exit(0);
}
$database = realpath($argv[2] ?? '');
if (! in_array($phase, ['produce', 'consume'], true) || ! $database
    || dirname($database) !== realpath($root.'/.build') || ! str_starts_with(basename($database), 'panel-queue-')) {
    throw new RuntimeException('Refusing non-fixture database or unknown phase.');
}
foreach (['APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array', 'APP_URL' => 'http://localhost'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $panel.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, function () use ($database): void {
    config(['app.version' => '1.0.0-rc.1', 'database.connections.sqlite.database' => $database, 'backups.throttles.period' => 0]);
});
$app->afterBootstrapping(RegisterFacades::class, fn () => Http::preventStrayRequests());
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception."\n");
    exit(1);
});
if ($app->runningUnitTests() || DB::connection()->getDatabaseName() !== $database) {
    throw new RuntimeException('Native plugin discovery must run against the isolated DB.');
}
// Intentionally no addPsr4(), manual provider registration or bus/queue fake.
$jobClass = 'IByte\\Restic\\Jobs\\PollOperation';
if ($phase === 'produce') {
    Node::created(fn ($node) => $node->update(['scheme' => 'https']));
    Artisan::call('migrate', ['--force' => true]);
    (require $panel.'/plugins/ibyte-restic/database/migrations/2026_10_14_000001_create_ibyte_restic_operations.php')->up();
    (require $panel.'/plugins/ibyte-restic/database/migrations/2026_10_15_000001_add_ibyte_restic_storage_metrics.php')->up();
    (require $panel.'/plugins/ibyte-restic/database/migrations/2026_10_15_000002_create_ibyte_restic_recovery_inventory.php')->up();
    (require $panel.'/plugins/ibyte-restic/database/migrations/2026_10_15_000003_create_ibyte_restic_retention.php')->up();
    (require $panel.'/plugins/ibyte-restic/database/migrations/2026_10_15_000004_create_ibyte_restic_key_history.php')->up();
    $server = Server::factory()->withNode()->create(['backup_limit' => 3]);
    $host = BackupHost::create(['name' => 'Fresh-process queue fixture', 'schema' => 'restic', 'configuration' => ['backend' => 'local', 'profile' => 'default', 'staging_enabled' => true, 'restore_fixture_enabled' => false]]);
    $host->nodes()->attach($server->node_id);
    Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/recovery') ? fixtureRecovery($request->data()) : ['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']], 200,
        ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$server->node->daemon_token_id.')']));
    $backup = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, 'Cross-process queued backup');
    if (DB::table('jobs')->count() !== 1 || $backup->completed_at || DB::table('ibyte_restic_operations')->where('request_uuid', $backup->uuid)->value('status') !== 'queued') {
        throw new RuntimeException('Producer did not durably dispatch exactly one pending operation.');
    }
    echo "PASS: native plugin boot, real after-commit job dispatch and SQLite persistence in producer process\n";
    exit(0);
}
if (class_exists($jobClass, false)) {
    throw new RuntimeException('Queue class unexpectedly loaded before deserialization; cold-loading test would be invalid.');
}
$backup = Backup::where('name', 'Cross-process queued backup')->firstOrFail();
$record = DB::table('ibyte_restic_operations')->where('request_uuid', $backup->uuid)->first();
$payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
$node = $backup->server->node;
Event::fake([BackupCompleted::class]);
$requests = 0;
Http::fake(function ($request) use ($node, $payload, &$requests) {
    if (! str_ends_with($request->url(), '/restic/jobs') || $request->data() !== $payload) {
        throw new RuntimeException('Unexpected consumer node request.');
    }
    $requests++;

    return Http::response($payload + ['status' => 'succeeded', 'snapshot' => str_repeat('b', 64), 'bytes' => 123], 200,
        ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')']);
});
$queue = Queue::connection('database');
$job = $queue->pop();
$raw = $job->getRawBody();
$job->fire(); // Native CallQueuedHandler -> unserialize -> plugin autoloader -> handle.
if (! class_exists($jobClass, false)
    || realpath((new ReflectionClass($jobClass))->getFileName()) !== realpath($panel.'/plugins/ibyte-restic/src/Jobs/PollOperation.php')) {
    throw new RuntimeException('Consumer did not autoload the actual installed plugin job.');
}
echo "PASS: unloaded job class resolves during native unserialize from installed plugin in a fresh consumer\n";
if (DB::table('jobs')->count() !== 1) {
    throw new RuntimeException('Successful backup did not queue exactly one retention job.');
}
$queue->pop()->fire(); // Native handler deserializes and runs ApplyRetention too.
if (! class_exists('IByte\\Restic\\Jobs\\ApplyRetention', false)) {
    throw new RuntimeException('Native queue did not autoload retention worker.');
}
echo "PASS: real queue deserializes installed retention worker after successful backup\n";
if (! $backup->fresh()->is_successful || $backup->fresh()->bytes !== 123 || ! $backup->fresh()->completed_at
    || DB::table('ibyte_restic_operations')->where('request_uuid', $backup->uuid)->value('status') !== 'succeeded'
    || DB::table('jobs')->count() !== 0 || $requests !== 1) {
    throw new RuntimeException('Actual queue consumer failed to persist completion/delete the queue job.');
}
echo "PASS: real queue handler reconciles mocked node result, persists native completion and removes its job\n";
$queue->pushRaw($raw);
$queue->pop()->fire();
Event::assertDispatchedTimes(BackupCompleted::class, 1);
if ($requests !== 1 || DB::table('jobs')->count() !== 0) {
    throw new RuntimeException('Duplicate queue payload repeated node work or remained pending.');
}
echo "PASS: replay of the original serialized payload is idempotent with one completion event\n";
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/readiness')
    ? ['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']]
    : $request->data() + ['status' => 'succeeded', 'bytes' => 0], 200,
    ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')']));
app(DeleteBackupService::class)->handle($backup->refresh());
$prune = DB::table('ibyte_restic_operations')->where('operation', 'prune')->firstOrFail();
if (Backup::whereKey($backup->id)->exists() || $queue->pop() !== null || $prune->status !== 'queued') {
    throw new RuntimeException('Forget did not defer prune until its maintenance delay.');
}
// Advance only disposable queue availability; do not wait ten minutes in a test.
DB::table('jobs')->update(['available_at' => now()->timestamp]);
$queue->pop()->fire();
if (DB::table('ibyte_restic_operations')->where('request_uuid', $prune->request_uuid)->value('status') !== 'succeeded' || DB::table('jobs')->count() !== 0) {
    throw new RuntimeException('Detached prune failed through actual serialized queue handler.');
}
echo "PASS: delayed prune runs through real queue after the last native backup row is deleted\n";
if (Artisan::call('restic:repair-installation') !== 0) {
    throw new RuntimeException('Fresh native plugin boot did not register working repair command.');
}
echo "PASS: fresh native CLI auto-registers additive repair command without manual provider or command registration\n";
if (Artisan::call('restic:diagnose') !== 0 || ! json_decode(Artisan::output(), true)['ready']) {
    throw new RuntimeException('Fresh native boot did not register working read-only runtime diagnostics.');
}
echo "PASS: fresh native CLI auto-registers read-only runtime diagnostics with matching safety bindings\n";
if (Artisan::call('help', ['command_name' => 'restic:diagnose-node']) !== 0 || ! str_contains(Artisan::output(), '--operation')) {
    throw new RuntimeException('Fresh native boot did not register the read-only node diagnostic command.');
}
echo "PASS: fresh native CLI auto-registers node readiness and saved-operation diagnostics without manual registration\n";

// Exercise native due-time selection and its delayed serialized RunTaskJob
// against wall-clock time, without Carbon travel or direct task-schema calls.
$timedServer = $backup->server;
$timedServer->update(['status' => null]);
$timedSchedule = Schedule::factory()->create(['server_id' => $timedServer->id, 'is_active' => true, 'is_processing' => false, 'only_when_online' => false, 'cron_minute' => '0', 'cron_hour' => '0', 'cron_day_of_month' => '*', 'cron_day_of_week' => '*', 'cron_month' => '*', 'next_run_at' => now()->addSeconds(3)]);
$timedTask = Task::factory()->create(['schedule_id' => $timedSchedule->id, 'action' => 'backup', 'payload' => '*.tmp', 'time_offset' => 2, 'sequence_id' => 1]);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/recovery') ? fixtureRecovery($request->data()) : (str_ends_with($request->url(), '/readiness') ? ['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']] : $request->data() + ['status' => 'succeeded', 'snapshot' => str_repeat('c', 64), 'bytes' => 45]), 200, ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')']));
Artisan::call('p:schedule:process');
if ($timedSchedule->fresh()->is_processing || DB::table('jobs')->count() !== 0) {
    throw new RuntimeException('Future schedule ran before its due time.');
}
echo "PASS: native schedule processor does not run a future backup schedule early\n";
usleep(3200000);
Artisan::call('p:schedule:process');
if (! $timedSchedule->fresh()->is_processing || ! $timedTask->fresh()->is_queued || $queue->pop() !== null) {
    throw new RuntimeException('Due schedule did not preserve its task delay.');
}
echo "PASS: real due-time selection queues the native backup task with its delay\n";
sleep(3);
$timedJob = $queue->pop();
if (! $timedJob) {
    throw new RuntimeException('Delayed task never became available.');
}
$timedJob->fire();
$timedBackup = $timedServer->backups()->where('is_scheduled', true)->sole();
if ($timedBackup->completed_at || $timedBackup->ignored_files !== ['*.tmp'] || $timedTask->fresh()->is_queued || $timedSchedule->fresh()->is_processing || ! $timedSchedule->fresh()->last_run_at) {
    throw new RuntimeException('Native scheduled task did not persist correct pending backup/schedule state.');
}
$queue->pop()->fire();
$queue->pop()->fire();
if (! $timedBackup->fresh()->is_successful || $timedBackup->fresh()->bytes !== 45 || DB::table('jobs')->count() !== 0) {
    throw new RuntimeException('Timed native backup failed to finish through real queue handlers.');
}
Artisan::call('p:schedule:process');
if (DB::table('jobs')->count() !== 0 || $timedServer->backups()->where('is_scheduled', true)->count() !== 1) {
    throw new RuntimeException('Native next-run advancement dispatched a duplicate scheduled backup.');
}
echo "PASS: delayed native task creates and completes one scheduled Restic backup through the real queue without duplicate dispatch (node responses mocked)\n";

// Actual native discovery, serialized database job and handler after server
// deletion. This verifies the Panel pipeline; remote Wings replies are mocked.
$destroyAdmin = $timedServer->user;
$destroyAdmin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
$destroyAdmin->update(['password' => Hash::make('queue-destroy-fixture')]);
$destroyServer = Server::factory()->create(['node_id' => $node->id]);
$destroyRepo = RecoveryRepository::where('server_uuid', $timedServer->uuid)->firstOrFail()->replicate();
$destroyRepo->fill(['server_uuid' => $destroyServer->uuid, 'metadata' => ['repository' => '/var/lib/ibyte-restic/repositories/queue-'.$destroyServer->uuid]])->save();
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/readiness') ? ['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local'], 'force_repository_delete' => 'local-v1'] : $request->data() + ['status' => 'succeeded'], 200,
    ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')']));
$destroyRequest = app(ServerDestruction::class)->run($destroyAdmin, $destroyServer, 'queue-destroy-fixture');
if (Server::whereKey($destroyServer->id)->exists() || ! DB::table('jobs')->where('payload', 'like', '%'.$destroyRequest.'%')->exists()) {
    throw new RuntimeException('Native force deletion did not persist a serialized destruction job.');
}
echo "PASS: native force deletion serializes original repository cleanup after the server disappears without queuing a final backup\n";
$queue->pop()->fire();
if (DB::table('ibyte_restic_operations')->where('request_uuid', $destroyRequest)->value('status') !== 'succeeded' || ! isset($destroyRepo->fresh()->metadata['repository_deleted_at'])) {
    throw new RuntimeException('Native serialized destruction job did not verify physical cleanup response.');
}
echo "PASS: real database queue executes repository destruction through native plugin autoload after server deletion (node responses mocked)\n";
