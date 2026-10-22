<?php

// Disposable, real upstream Laravel/SQLite tests. Never bootstrap an operator Panel.
declare(strict_types=1);

// This one-process fixture renders many native Livewire pages; keep its memory
// allowance independent of the operator's PHP configuration.
ini_set('memory_limit', '512M');

use App\Enums\PluginStatus;
use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Events\Server\BackupCompleted;
use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Extensions\Tasks\Schemas\CreateBackupSchema;
use App\Http\Controllers\Api\Client\Servers\BackupController;
use App\Http\Requests\Api\Client\Servers\Backups\RestoreBackupRequest;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Helpers\PluginService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use IByte\Restic\Admin\HealthActions;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Backups\DownloadController;
use IByte\Restic\Integration\BridgeRepository;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Providers\ResticProvider;
use IByte\Restic\ResticPlugin;
use IByte\Restic\ResticSchema;
use IByte\Restic\Support\Compatibility;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

$root = dirname(__DIR__);
$releasePins = json_decode(file_get_contents($root.'/compatibility/versions.json'), true, flags: JSON_THROW_ON_ERROR);
$panelZipName = 'ibyte-restic-v'.($releasePins['panel_release'] ?? $releasePins['release']).'.zip';
require $root.'/scripts/fixtures/recovery-response.php';
require $root.'/scripts/fixtures/prepared-final.php';
$panel = realpath($root.'/.upstream/panel');
if (! $panel || ! is_file($panel.'/vendor/autoload.php')) {
    throw new RuntimeException('Fetch pinned Panel and install its locked dependencies first.');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'APP_URL' => 'http://localhost'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$loader = require $panel.'/vendor/autoload.php';
$loader->addPsr4('IByte\\Restic\\', $root.'/panel-plugin/src/');
$app = require $panel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Calendar fixtures must not change behavior when the wall-clock day advances.
$fixtureNow = CarbonImmutable::parse('2026-10-15 23:00:00', 'UTC');
Carbon::setTestNow($fixtureNow);
CarbonImmutable::setTestNow($fixtureNow);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'queue.default' => 'database', 'cache.default' => 'array']);
DB::purge();
if (DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Refusing non-memory DB');
}
Artisan::call('migrate', ['--force' => true]);
$migration = require $root.'/panel-plugin/database/migrations/2026_10_14_000001_create_ibyte_restic_operations.php';
$migration->up();
$app->register(ResticProvider::class);
// Only disposable factories: credential escrow deliberately requires HTTPS,
// even though HTTP itself remains faked and stray requests are forbidden.
Node::created(fn (Node $node) => $node->update(['scheme' => 'https']));
app('router')->getRoutes()->refreshNameLookups();
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e."\n");
    exit(1);
});

$passed = 0;
function check(bool $value, string $name): void
{
    global $passed;
    if (! $value) {
        throw new RuntimeException('FAIL: '.$name);
    } $passed++;
    echo "PASS: $name\n";
}
function rejected(callable $fn, string $name, ?string $message = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($message !== null && ! str_contains($e->getMessage(), $message)) {
            throw $e;
        } check(true, $name);

        return;
    } throw new RuntimeException('FAIL (accepted): '.$name);
}

Compatibility::assertSupported();
$registry = app(BackupAdapterService::class);
check(array_keys($registry->getMappings()) === ['wings', 's3', 'restic'], 'actual container registers third schema and preserves Wings/S3');
check(app(DaemonBackupRepository::class) instanceof BridgeRepository, 'native restore dependency resolves plugin bridge');
$fields = $registry->get('restic')->getConfigurationForm();
check(count($fields) === 4 && $fields[1]->getName() === 'configuration.profile', 'real Filament Restic form fields');

// Exercise the real official ZIP import and install service. Frontend asset build commands
// are faked explicitly; this is not a browser import/install test.
config(['app.version' => '1.0.0-rc.1']);
Process::fake();
$upload = new UploadedFile($root.'/release/Panel Plugin/'.$panelZipName, 'renamed-plugin.zip', 'application/zip', null, true);
$plugins = app(PluginService::class);
$imported = $plugins->downloadPluginFromFile($upload);
check($imported === 'ibyte-restic', 'official plugin ZIP import accepts package root and derives correct ID');
Plugin::refreshRows();
$plugin = Plugin::findOrFail('ibyte-restic');
check($plugin->isCompatible() && in_array(ResticProvider::class, $plugin->getProviders(), true), 'official plugin discovery finds provider and exact version');
$plugins->installPlugin($plugin);
check(Plugin::findOrFail('ibyte-restic')->status === PluginStatus::Enabled, 'official install lifecycle enables plugin and runs plugin migration (asset commands mocked)');
check(Schema::hasColumn('ibyte_restic_operations', 'metrics'), 'native plugin installer applies the new metric migration to the pre-existing operations table');

// This fixture boots before importing its plugin; production discovers it at boot.
(new ResticPlugin)->register(Filament::getPanel('admin'));
Route::prefix('admin')->name('filament.admin.')->group(fn () => RecoveryResource::registerRoutes(Filament::getPanel('admin')));
app('router')->getRoutes()->refreshNameLookups();

Bus::fake([PollOperation::class]);
Event::fake([BackupCompleted::class]);
$server = Server::factory()->withNode()->create(['backup_limit' => 2]);
$host = BackupHost::create(['name' => 'Disposable Restic', 'schema' => 'restic', 'configuration' => ['profile' => 'test', 'backend' => 'local', 'staging_enabled' => true, 'restore_fixture_enabled' => true]]);
$host->nodes()->attach($server->node_id);
Http::preventStrayRequests();
$mode = 'running';
$deleteExists = false;
$forged = false;
$offline = false;
$wrongIdentity = false;
Http::fake(function (Illuminate\Http\Client\Request $request) use (&$mode, &$deleteExists, &$forged, &$offline, &$wrongIdentity, $server) {
    if ($offline) {
        return Http::failedConnection();
    }
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$server->node->daemon_token_id.')'];
    if ($wrongIdentity) {
        $headers['User-Agent'] = 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:wrong)';
    }
    if (str_ends_with($request->url(), '/restic/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['test' => 'local']], 200, $headers);
    }
    if (str_contains($request->url(), '/restic/')) {
        $p = $request->data();
        if (str_ends_with($request->url(), '/server-key')) {
            return Http::response(['prepared' => true], 200, $headers);
        }
        if (str_ends_with($request->url(), '/recovery')) {
            return Http::response(fixtureRecovery($p), 200, $headers);
        }
        if (str_ends_with($request->url(), '/export')) {
            return Http::response('fixture archive bytes', 200, $headers + ['Content-Type' => 'application/x-tar']);
        }
        if ($p['operation'] === 'delete' && str_ends_with($request->url(), '/status') && ! $deleteExists) {
            return Http::response([], 404, $headers);
        }
        if ($forged) {
            $p['request_id'] = 'forged-response-id';
        }

        return Http::response($p + ['status' => $mode, 'snapshot' => str_repeat('a', 64), 'bytes' => 42], 200, $headers);
    }
    if (str_contains($request->url(), '/backup/')) {
        return Http::response([], 202, $headers);
    }

    return Http::response([], 404, $headers);
});
$backup = app(InitiateBackupService::class)->setIgnoredFiles(['*.log'])->handle($server, 'Native manual backup');
check(app(Transport::class)->client($server->node)->getOptions()['verify'] === true, 'Panel-to-node TLS verification forced even in testing/local environment');
check($backup->backup_host_id === $host->id && ! $backup->completed_at, 'native InitiateBackupService queues Restic after quota and host selection');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $backup->uuid)->exists(), 'durable operation stored');
Bus::assertDispatched(PollOperation::class);
$poll = new PollOperation($backup->uuid);
$mode = 'succeeded';
$poll->handle(app(Transport::class));
$backup->refresh();
check($backup->is_successful && $backup->bytes === 42 && $backup->checksum === null, 'native backup completion fields reflect verified node result');
Event::assertDispatched(BackupCompleted::class);
$poll->handle(app(Transport::class));
Event::assertDispatchedTimes(BackupCompleted::class, 1);
check(true, 'duplicate completion does not duplicate native completion event');

$task = new Task(['payload' => '*.tmp']);
$task->setRelation('server', $server);
app(CreateBackupSchema::class)->runTask($task);
$scheduled = $server->backups()->where('is_scheduled', true)->firstOrFail();
check($scheduled->backup_host_id === $host->id, 'actual native schedule task dispatches the same Restic provider');
rejected(fn () => app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server), 'native creation throttle remains effective', 'throttle has been reached');
config(['backups.throttles.period' => 0]);
$backup->update(['is_locked' => true]);
$scheduled->update(['is_locked' => true]);
rejected(fn () => app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, null, true), 'native quota rejects all-locked scheduled replacement', 'No completed unlocked');
rejected(fn () => app(DeleteBackupService::class)->handle($backup), 'native deletion rejects locked backup', 'marked as locked');
check(! $server->user->can(SubuserPermission::BackupRead, Server::factory()->withNode()->create()), 'cross-tenant native permission denied');
$stranger = User::factory()->create();
check(! $stranger->can(SubuserPermission::BackupRestore, $server), 'wrong user cannot restore');

rejected(fn () => $host->update(['configuration' => ['profile' => 'evil', 'backend' => 'local']]), 'host configuration cannot move existing repositories');
$host->refresh();
$backup->setRelation('backupHost', $host);
$bridge = app(DaemonBackupRepository::class)->setServer($server);
rejected(fn () => $bridge->restore($backup, null, false), 'restore requires native destructive confirmation', 'confirmation');
$blockedHost = clone $host;
$blockedHost->configuration = array_merge($host->configuration, ['restore_fixture_enabled' => false]);
$backup->setRelation('backupHost', $blockedHost);
rejected(fn () => $bridge->restore($backup, null, true), 'native restore fails closed without explicit isolated fixture opt-in', 'write fence is missing');
$backup->unsetRelation('backupHost');
$request = RestoreBackupRequest::create('/', 'POST', ['truncate' => true]);
$request->setUserResolver(fn () => $server->user);
app(BackupController::class)->restore($request, $server, $backup);
check($server->fresh()->status === ServerState::RestoringBackup, 'actual native restore controller queues plugin restore and protects server');
$restore = DB::table('ibyte_restic_operations')->where('operation', 'restore')->first();
(new PollOperation($restore->request_uuid))->handle(app(Transport::class));
check($server->fresh()->status === null, 'verified restore success clears native protection');
$mode = 'failed';
app(BackupController::class)->restore($request, $server->fresh(), $backup);
$failedRestore = DB::table('ibyte_restic_operations')->where('operation', 'restore')->where('status', 'queued')->firstOrFail();
(new PollOperation($failedRestore->request_uuid))->handle(app(Transport::class));
check($server->fresh()->status === ServerState::RestoringBackup, 'failed native restore retains protection and is not reported healthy');
$server->update(['status' => null]);
$mode = 'succeeded';
$wrongIdentity = true;
rejected(fn () => app(Transport::class)->context($backup), 'wrong daemon response identity rejected', 'handshake failed');
$wrongIdentity = false;

$signedDownload = app(ResticSchema::class)->getDownloadLink($backup, $server->user);
$downloadRequest = Request::create($signedDownload);
check(URL::hasValidSignature($downloadRequest), 'native framework validates generated short-lived download signature');
$tampered = Request::create($signedDownload.'&tampered=1');
check(! URL::hasValidSignature($tampered), 'tampered download URL signature rejected');
$downloadRequest->setUserResolver(fn () => $server->user);
$download = app(DownloadController::class)($downloadRequest, $backup, app(Transport::class));
ob_start();
$download->sendContent();
$bytes = ob_get_clean();
check($bytes === 'fixture archive bytes', 'native download proxy streams authenticated node body');
rejected(fn () => app(DownloadController::class)($downloadRequest, $backup, app(Transport::class)), 'download authorization is one-use');
$downloadRequest->setUserResolver(fn () => $stranger);
rejected(fn () => app(DownloadController::class)($downloadRequest, $backup, app(Transport::class)), 'cross-tenant direct download rejected');
rejected(fn () => HealthActions::queue($stranger, $host, 'check'), 'repository health rejected for non-admin');

$backup->update(['is_locked' => false]);
$deleteExists = true;
rejected(fn () => $backup->update(['is_locked' => true]), 'late lock rejected after ambiguous daemon delete request');
$backup->refresh();
$deleteExists = false;
$server->update(['backup_limit' => 10]);
$beforeCount = Backup::count();
config(['queue.default' => 'sync']);
rejected(fn () => app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server), 'synchronous queue refused before node work', 'durable Panel queue');
check(Backup::count() === $beforeCount, 'queue preflight failure rolls back native backup record');
config(['queue.default' => 'database']);
$forgedBackup = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, 'Forged response test');
$forged = true;
(new PollOperation($forgedBackup->uuid))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $forgedBackup->uuid)->value('status') === 'unknown' && ! $forgedBackup->fresh()->is_successful, 'forged completion never succeeds');
$forged = false;
$offlineBackup = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, 'Offline test');
$offline = true;
(new PollOperation($offlineBackup->uuid))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $offlineBackup->uuid)->value('status') === 'queued' && ! $offlineBackup->fresh()->completed_at, 'network ambiguity remains durable and pending');
$offline = false;
(new PollOperation($offlineBackup->uuid))->handle(app(Transport::class));
check($offlineBackup->fresh()->is_successful, 'new queue job instance reconciles persisted request after outage');

$originalNode = $server->node_id;
$otherNode = Node::factory()->create();
$host->nodes()->attach($otherNode->id);
rejected(fn () => $server->update(['node_id' => $otherNode->id]), 'direct node changes blocked while Restic backups remain', 'Restic blocks');
// Deliberately bypass model events to simulate an out-of-band DB change;
// transport must retain its independent immutable-node safety check.
DB::table('servers')->where('id', $server->id)->update(['node_id' => $otherNode->id]);
$backup->unsetRelation('server');
rejected(fn () => app(Transport::class)->context($backup, false), 'moved server cannot access original node repository');
DB::table('servers')->where('id', $server->id)->update(['node_id' => $originalNode]);
$server->refresh();
$backup->unsetRelation('server');
$mode = 'succeeded';
app(DeleteBackupService::class)->handle($backup);
check(! Backup::whereKey($backup->id)->exists() && Backup::whereKey($scheduled->id)->exists(), 'native unlocked deletion removes one record and preserves other backups');

$host->nodes()->detach($server->node_id);
rejected(fn () => app(Transport::class)->context($scheduled), 'unlinked node rejected');
$host->nodes()->attach($server->node_id);
rejected(fn () => $migration->down(), 'plugin migration blocks destructive uninstall with existing hosts/history');
rejected(fn () => $plugins->uninstallPlugin(Plugin::findOrFail('ibyte-restic')), 'official uninstall service preserves active Restic history');
check(Schema::hasTable('servers') && DB::table('ibyte_restic_operations')->exists(), 'failed uninstall preserves native and plugin tables');

// The internal shim forwards stock restoration arguments unchanged for non-Restic providers.
$wings = BackupHost::create(['name' => 'Wings', 'schema' => 'wings', 'configuration' => []]);
$native = Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $wings->id]);
$result = app(DaemonBackupRepository::class)->setServer($server)->restore($native, 'https://example.test/archive', false);
check($result->status() === 202, 'Wings restore delegates to original repository');
Http::assertSent(fn ($r) => str_contains($r->url(), '/backup/'.$native->uuid.'/restore') && $r['adapter'] === 'wings' && $r['download_url'] === 'https://example.test/archive' && $r['truncate_directory'] === false);
$native->setRelation('backupHost', new BackupHost(['schema' => 's3']));
check(app(DaemonBackupRepository::class)->setServer($server)->restore($native, 'https://example.test/s3', true)->status() === 202, 'S3 restore delegates to original repository');
require $root.'/panel-plugin/tests/host-form.php';
require $root.'/panel-plugin/tests/backup-create.php';
require $root.'/panel-plugin/tests/storage-lifecycle.php';
require $root.'/panel-plugin/tests/recovery.php';
require $root.'/panel-plugin/tests/retention.php';
require $root.'/panel-plugin/tests/installation.php';
require $root.'/panel-plugin/tests/automation.php';
require $root.'/panel-plugin/tests/stale-records.php';
require $root.'/panel-plugin/tests/server-keys.php';
require $root.'/panel-plugin/tests/runtime-diagnostics.php';
require $root.'/panel-plugin/tests/repository-controls.php';
require $root.'/panel-plugin/tests/forced-cleanup.php';
require $root.'/panel-plugin/tests/repository-generations.php';
require $root.'/panel-plugin/tests/admin-navigation.php';
require $root.'/panel-plugin/tests/hardening.php';
require $root.'/panel-plugin/tests/final-backup.php';
require $root.'/panel-plugin/tests/repository-export.php';
require $root.'/panel-plugin/tests/compatibility.php';
require $root.'/panel-plugin/tests/alerts-diagnostics.php';
require $root.'/panel-plugin/tests/server-destruction.php';

echo "Panel source + SQLite/Livewire tests passed: $passed\n";
