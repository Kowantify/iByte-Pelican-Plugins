<?php

use App\Events\Server\BackupCompleted;
use App\Exceptions\Service\Backup\BackupLockedException;
use App\Http\Controllers\Api\Client\Servers\BackupController;
use App\Http\Requests\Api\Client\Servers\Backups\RestoreBackupRequest;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Backups\DownloadController;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\FinalBackupPreparation;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RepositoryExport;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\RetainedMaintenance;
use IByte\Restic\ResticSchema;
use IByte\Restic\Storage\StorageReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Real local Panel -> HTTPS Wings -> Restic. Never an operator environment.
if (getenv('IBYTE_DISPOSABLE_LINUX') !== '1' || ! is_file('/opt/ibyte-acceptance/fixture') || PHP_OS_FAMILY !== 'Linux') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$panel = $root.'/.upstream/panel';
require $panel.'/vendor/autoload.php';
foreach (['APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array', 'APP_URL' => 'https://localhost', 'MAIL_MAILER' => 'log', 'BROADCAST_CONNECTION' => 'log'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $panel.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, fn () => config(['app.version' => '1.0.0-rc.1', 'backups.throttles.period' => 0]));
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception."\n");
    exit(1);
});
Artisan::call('migrate', ['--force' => true]);
foreach (glob($panel.'/plugins/ibyte-restic/database/migrations/*.php') as $migration) {
    (require $migration)->up();
}
function liveCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    } echo "PASS: $message\n";
}
function liveWait(callable $done): void
{
    $queue = Queue::connection('database');
    $deadline = microtime(true) + 100;
    do {
        while ($job = $queue->pop()) {
            $job->fire();
        }
        if ($done()) {
            return;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    $states = DB::table('ibyte_restic_operations')->get(['operation', 'status', 'error']);
    throw new RuntimeException('Live fixture timed out: '.$states->toJson());
}
$node = Node::factory()->create();
$node->forceFill(['scheme' => 'https', 'fqdn' => 'localhost', 'daemon_listen' => 18080, 'daemon_connect' => 18080, 'daemon_token_id' => 'acceptance', 'daemon_token' => 'disposable-acceptance-token'])->save();
liveCheck($node->id === 1, 'fresh disposable native database uses expected node identity');
$server = Server::factory()->create(['node_id' => $node->id, 'uuid' => '11111111-1111-4111-8111-111111111111', 'status' => null, 'backup_limit' => 5]);
$host = BackupHost::create(['name' => 'Real Linux fixture', 'schema' => 'restic', 'configuration' => ['backend' => 'local', 'profile' => 'default', 'staging_enabled' => true, 'restore_fixture_enabled' => true]]);
$host->nodes()->attach($node->id);
$admin = $server->user;
$admin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
$admin->update(['password' => Hash::make('disposable-live-fixture')]);
auth()->login($admin);
if (getenv('IBYTE_TEST_SERVER_DESTRUCTION_ONLY') === '1') {
    require __DIR__.'/server-destruction.php';
    exit;
}
// Completion broadcasting alone is omitted; HTTP, native queue, services,
// migrations, file data, TLS and daemon authentication are real.
Event::fake([BackupCompleted::class]);
$world = '/var/lib/ibyte-acceptance/volumes/'.$server->uuid.'/world.dat';
liveCheck(is_dir(dirname($world)) && ! is_link(dirname($world)), 'real Wings owns the disposable game root');
$firstContent = str_repeat("first-world\n", 200);
file_put_contents($world, $firstContent);
$probe = new Backup(['uuid' => (string) Str::uuid(), 'server_id' => $server->id, 'backup_host_id' => $host->id]);
$probe->setRelation('server', $server)->setRelation('backupHost', $host);
$probeContext = app(Transport::class)->context($probe, false);
$rawProbe = (new DaemonBackupRepository)->setNode($node)->getHttpClient()->withOptions(['verify' => true])->withoutRedirecting()->get('/api/system');
echo json_encode(['system_http' => $rawProbe->status(), 'actual_agent' => preg_replace('/\(id:[^)]*\)/', '(id:redacted)', $rawProbe->header('User-Agent') ?? ''), 'identity_header_matches' => $rawProbe->header('User-Agent') === 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')']), "\n";
foreach (['server-key', 'recovery'] as $endpoint) {
    $probeResponse = app(Transport::class)->client($node)->post('/api/servers/'.$server->uuid.'/restic/'.$endpoint, $probeContext);
    liveCheck($probeResponse->successful(), 'live '.$endpoint.' preflight HTTP status '.$probeResponse->status());
    if ($endpoint === 'recovery') {
        $info = $probeResponse->json();
        echo json_encode(['credential_mode' => $info['credential_mode'] ?? null, 'key_finalized_type' => get_debug_type($info['key_finalized'] ?? null), 'retention_safe' => $info['retention_safe'] ?? null, 'identity_matches' => array_map(fn ($key) => ($info[$key] ?? null) === $probeContext[$key], ['host', 'node', 'server', 'profile', 'backend'])]), "\n";
    }
}
$first = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, 'Real Linux native backup');
liveWait(fn () => $first->fresh()->completed_at !== null);
liveCheck($first->fresh()->is_successful, 'native queued backup completes against real HTTPS Wings and Restic');
$repo = RecoveryRepository::where('server_uuid', $server->uuid)->sole();
liveCheck($repo->secret !== null && StorageReport::repository($repo) !== null, 'real backup automatically persists encrypted recovery key and measured storage');
$link = app(ResticSchema::class)->getDownloadLink($first->fresh(), $admin);
$request = Request::create($link);
$request->setUserResolver(fn () => $admin);
$download = app(DownloadController::class)($request, $first->fresh(), app(Transport::class));
ob_start();
$download->sendContent();
$archive = ob_get_clean();
$archivePath = '/opt/ibyte-acceptance/tmp/live-export.tar';
file_put_contents($archivePath, $archive);
$tar = new Process(['tar', '-xOf', $archivePath, 'world.dat']);
$tar->mustRun();
liveCheck($tar->getOutput() === $firstContent, 'native signed download streams a real archive with byte-identical game content');
$first->update(['is_locked' => true]);
try {
    app(DeleteBackupService::class)->handle($first->fresh());
    throw new RuntimeException('Locked deletion unexpectedly accepted');
} catch (BackupLockedException $exception) {
}
liveCheck($first->fresh()->is_locked, 'native lock refuses deletion of a real snapshot');
$first->update(['is_locked' => false]);
$scheduledContent = str_repeat("scheduled-world\n", 200);
file_put_contents($world, $scheduledContent);
$schedule = Schedule::factory()->create(['server_id' => $server->id, 'is_active' => true, 'is_processing' => false, 'only_when_online' => false, 'cron_minute' => '0', 'cron_hour' => '0', 'next_run_at' => now()->addSeconds(2)]);
Task::factory()->create(['schedule_id' => $schedule->id, 'action' => 'backup', 'payload' => '*.tmp', 'time_offset' => 1, 'sequence_id' => 1]);
Artisan::call('p:schedule:process');
liveCheck(! $schedule->fresh()->is_processing, 'real-node schedule does not dispatch before its due time');
sleep(3);
Artisan::call('p:schedule:process');
liveWait(fn () => $server->backups()->where('is_scheduled', true)->where('is_successful', true)->exists());
$scheduled = $server->backups()->where('is_scheduled', true)->sole();
liveCheck($scheduled->ignored_files === ['*.tmp'] && $schedule->fresh()->last_run_at !== null, 'timed native schedule and serialized queue create a real Restic snapshot');
file_put_contents($world, 'disposable replacement world');
$restoreRequest = RestoreBackupRequest::create('/', 'POST', ['truncate' => true]);
$restoreRequest->setUserResolver(fn () => $admin);
app(BackupController::class)->restore($restoreRequest, $server->fresh(), $first->fresh());
liveWait(fn () => DB::table('ibyte_restic_operations')->where('operation', 'restore')->where('status', 'succeeded')->exists());
liveCheck(file_get_contents($world) === $firstContent && $server->fresh()->status === null, 'isolated native restore selects the older snapshot and verifies real bytes (no competing writers)');
liveCheck(FinalBackupPreparation::ready($admin, $server->fresh(), 'disposable-live-fixture', $server->uuid, 'delete') === false && Server::whereKey($server->id)->exists(), 'real final-backup preparation queues a new locked backup and preserves the server');
$final = $server->backups()->where('name', 'Final backup before delete')->sole();
liveWait(fn () => $final->fresh()->completed_at !== null);
liveCheck($final->fresh()->is_successful && $final->fresh()->is_locked && FinalBackupPreparation::ready($admin, $server->fresh(), 'disposable-live-fixture', $server->uuid, 'delete'), 'real recent locked final snapshot permits second confirmation');
app(RetainedDeletion::class)->transition($admin, $server->fresh(), 'disposable-live-fixture', $server->uuid, 'delete', fn ($fresh) => app(ServerDeletionService::class)->handle($fresh));
liveCheck(! Server::whereKey($server->id)->exists() && DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->whereNull('forgotten_at')->value('backup_uuid') === $final->uuid && DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->whereNull('forgotten_at')->count() === 1, 'real native server deletion forgets older snapshots and preserves only the verified final mapping and keys');
$maintenance = RetainedMaintenance::queue($admin, $repo->fresh(), 'stats');
liveWait(fn () => DB::table('ibyte_restic_operations')->where('request_uuid', $maintenance)->value('status') === 'succeeded');
liveCheck(StorageReport::repository($repo->fresh()) !== null, 'retained repository statistics complete through real queue after native server deletion');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$admin->id);
$retainedUrl = RepositoryExport::issue($admin, $repo->fresh(), 'disposable-live-fixture', $final->uuid, 'tar.gz');
$retainedRequest = Request::create($retainedUrl);
$retainedRequest->setUserResolver(fn () => $admin);
$retainedResponse = app(RepositoryExport::class)($retainedRequest, $repo->fresh(), app(Transport::class));
ob_start();
$retainedResponse->sendContent();
$retainedArchive = ob_get_clean();
$retainedPath = '/opt/ibyte-acceptance/tmp/live-retained.tar.gz';
file_put_contents($retainedPath, $retainedArchive);
$retainedTar = new Process(['tar', '-xzOf', $retainedPath, 'world.dat']);
$retainedTar->mustRun();
liveCheck($retainedTar->getOutput() === $firstContent, 'password-confirmed retained gzip export recovers real bytes after native server deletion');
echo "Live local native integration passed; control-plane bootstrap inventory and completion broadcast were mocked, not daemon/Restic traffic. Production writer fencing remains blocked.\n";
