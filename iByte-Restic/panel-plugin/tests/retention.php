<?php

use App\Enums\PluginStatus;
use App\Extensions\Tasks\TaskService;
use App\Filament\Server\Resources\Backups\BackupResource;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Jobs\Plugin\InstallPlugin;
use App\Jobs\Schedule\RunTaskJob;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Plugin;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\Task;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use IByte\Restic\Admin\HealthActions;
use IByte\Restic\Backups\DownloadController;
use IByte\Restic\Backups\Retention;
use IByte\Restic\Jobs\ApplyRetention;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\ResticSchema;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

check(Schema::hasTable('ibyte_restic_retention'), 'native plugin install applies retention migration');
Bus::fake([PollOperation::class, ApplyRetention::class]);
$retentionServer = Server::factory()->withNode()->create(['backup_limit' => 2]);
$retentionHost = BackupHost::create(['name' => 'Retention fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
$retentionHost->nodes()->attach($retentionServer->node_id);
Http::swap(new Factory);
Http::preventStrayRequests();
$retentionMode = 'succeeded';
$forgetMode = 'succeeded';
$forgotten = [];
$readinessOffline = false;
Http::fake(function ($request) use ($retentionServer, &$retentionMode, &$forgotten, &$forgetMode, &$readinessOffline) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$retentionServer->node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return $readinessOffline ? Http::failedConnection() : Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']], 200, $headers);
    }
    $p = $request->data();
    if (str_ends_with($request->url(), '/recovery')) {
        return Http::response(fixtureRecovery($p), 200, $headers);
    }
    if (($p['operation'] ?? null) === 'delete' && str_ends_with($request->url(), '/status') && ! isset($forgotten[$p['backup']])) {
        return Http::response([], 404, $headers);
    }
    if (($p['operation'] ?? null) === 'delete') {
        if ($forgetMode === 'forged') {
            return Http::response(array_merge($p, ['backup' => '00000000-0000-4000-8000-000000000000', 'status' => 'succeeded', 'bytes' => 0]), 200, $headers);
        }
        if ($forgetMode === 'succeeded') {
            $forgotten[$p['backup']] = true;
        }

        return Http::response($p + ['status' => $forgetMode, 'bytes' => 0], 200, $headers);
    }

    return Http::response($p + ['status' => $retentionMode, 'snapshot' => str_repeat('a', 64), 'bytes' => 123], 200, $headers);
});
config(['queue.default' => 'database', 'backups.throttles.period' => 0]);
Auth::login($retentionServer->user);
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($retentionServer);
// A real server-panel boot installs this scope; this multi-panel CLI fixture
// switches panels in one process and must perform the same registration.
BackupResource::registerTenancyModelGlobalScope(Filament::getPanel('server'));
$makeRetentionBackup = function (string $name) use ($retentionServer) {
    $b = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($retentionServer, $name);
    (new PollOperation($b->uuid))->handle(app(Transport::class));

    return $b->fresh();
};
$firstRetention = $makeRetentionBackup('oldest unlocked');
$secondRetention = $makeRetentionBackup('newer locked');
$secondRetention->update(['is_locked' => true]);
$callsBefore = count($forgotten);
$readinessOffline = true;
rejected(fn () => $makeRetentionBackup('offline refusal'), 'offline preflight refuses replacement before forget');
$readinessOffline = false;
check(count($forgotten) === $callsBefore && $firstRetention->fresh() !== null, 'offline preflight preserves old backup metadata and snapshot');
config(['backups.throttles.period' => 60, 'backups.throttles.limit' => 1]);
rejected(fn () => $makeRetentionBackup('throttled replacement'), 'throttling is checked before retention deletion', 'throttle');
check(count($forgotten) === $callsBefore, 'throttled create never forgets existing snapshots');
config(['backups.throttles.period' => 0]);
rejected(fn () => app(InitiateBackupService::class)->setIgnoredFiles(['--injected'])->handle($retentionServer), 'invalid exclude is rejected before quota eviction', 'No snapshot was removed');
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer, str_repeat('x', 256)), 'overlong backup name is rejected before quota eviction', 'No snapshot was removed');
check(count($forgotten) === $callsBefore, 'invalid create payload never forgets an existing snapshot');
$forgetMode = 'failed';
rejected(fn () => $makeRetentionBackup('failed forget'), 'failed forget rejects new backup without claiming deletion', 'Snapshot deletion was not confirmed');
check($firstRetention->fresh() !== null && $retentionServer->backups()->count() === 2, 'failed forget preserves both native records and quota');
$forgetMode = 'forged';
rejected(fn () => $makeRetentionBackup('wrong deletion identity'), 'forged delete completion cannot release a quota slot', 'Deletion identity');
check($firstRetention->fresh() !== null && $retentionServer->backups()->count() === 2, 'wrong-identity delete completion preserves native metadata');
$forgetMode = 'succeeded';
Livewire::test(ListBackups::class)->callTableAction('create', data: ['name' => 'manual full quota', 'ignored' => '', 'is_locked' => false])->assertStatus(200);
$manualRetention = $retentionServer->backups()->where('name', 'manual full quota')->firstOrFail();
check($firstRetention->fresh() === null && $secondRetention->fresh()->is_locked && isset($forgotten[$firstRetention->uuid]), 'real native manual action at capacity replaces oldest unlocked backup and preserves locked copy');
check($retentionServer->backups()->nonFailed()->count() === 2 && ! $manualRetention->completed_at, 'quota includes new in-progress backup without exceeding limit');
$prune = DB::table('ibyte_restic_operations')->where('server_uuid', $retentionServer->uuid)->where('operation', 'prune')->firstOrFail();
check($prune->status === 'queued' && ! Backup::where('uuid', $prune->backup_uuid)->exists(), 'confirmed forget queues durable prune independently of deleted backup row');
(new PollOperation($manualRetention->uuid))->handle(app(Transport::class));
Bus::assertDispatched(ApplyRetention::class, fn ($job) => $job->serverUuid === $retentionServer->uuid);
check(true, 'verified successful create queues calendar retention');

// Real native schedule job -> task registry -> same initiation bridge at capacity.
$schedule = Schedule::factory()->create(['server_id' => $retentionServer->id, 'is_active' => true, 'is_processing' => true]);
$scheduledTask = Task::factory()->create(['schedule_id' => $schedule->id, 'action' => 'backup', 'payload' => '*.tmp', 'sequence_id' => 1, 'is_queued' => true]);
(new RunTaskJob($scheduledTask))->handle(app(TaskService::class));
$scheduledRetention = $retentionServer->backups()->where('is_scheduled', true)->firstOrFail();
check($manualRetention->fresh() === null && $scheduledRetention->ignored_files === ['*.tmp'] && $retentionServer->backups()->nonFailed()->count() === 2, 'actual native schedule job replaces eligible backup and respects ignore rules and quota');
check(! $schedule->fresh()->is_processing && ! $scheduledTask->fresh()->is_queued && $schedule->fresh()->last_run_at !== null, 'native schedule completion and task bookkeeping remain intact');
check(DB::table('ibyte_restic_operations')->where('server_uuid', $retentionServer->uuid)->where('operation', 'prune')->count() === 1, 'multiple confirmed forgets coalesce pending prune work per repository');
(new PollOperation($prune->request_uuid))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $prune->request_uuid)->value('status') === 'succeeded', 'prune completion reconciles after its anchor backup was deleted');
(new PollOperation($scheduledRetention->uuid))->handle(app(Transport::class));
$scheduledRetention->refresh()->update(['is_locked' => true]);
$beforeAllLocked = count($forgotten);
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer, null, true), 'all-locked scheduled replacement is a clear handled conflict', 'No completed unlocked');
check(count($forgotten) === $beforeAllLocked, 'all-locked quota never submits a delete');
$scheduledRetention->update(['is_locked' => false]);
$retentionServer->update(['backup_limit' => 0]);
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer, null, true), 'zero backup quota cannot be bypassed', 'no backup slots');
$retentionServer->update(['backup_limit' => 2]);
$pendingRetention = app(InitiateBackupService::class)->handle($retentionServer, 'pending protected');
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer, null, true), 'pending backups are not evicted to make another slot', 'No completed unlocked');

// Failed completion must not schedule cleanup of other usable snapshots.
Bus::fake([PollOperation::class, ApplyRetention::class]);
$retentionMode = 'failed';
(new PollOperation($pendingRetention->uuid))->handle(app(Transport::class));
Bus::assertNotDispatched(ApplyRetention::class);
check(! $pendingRetention->fresh()->is_successful && $pendingRetention->fresh()->completed_at !== null, 'failed creation marks failure and triggers no retention');
$retentionMode = 'succeeded';
$retentionServer->update(['backup_limit' => 20]);
$policyData = ['enabled' => true, 'daily' => 1, 'weekly' => 1, 'timezone' => 'America/Chicago'];
$strangerRetention = User::factory()->create();
rejected(fn () => Retention::save($strangerRetention, $retentionServer, $policyData), 'cross-tenant retention modification denied');
rejected(fn () => Retention::save($retentionServer->user, $retentionServer, array_merge($policyData, ['daily' => 19])), 'tier reservations cannot exhaust hourly/new backup slots');
rejected(fn () => Retention::save($retentionServer->user, $retentionServer, array_merge($policyData, ['timezone' => 'invalid/timezone'])), 'invalid retention timezone rejected');
Livewire::test(ListBackups::class)->callTableAction('ibyte-restic-retention', data: $policyData)->assertStatus(200)->assertHasNoTableActionErrors();
check((int) Retention::policy($retentionServer)['daily'] === 1 && (int) Retention::policy($retentionServer)['weekly'] === 1, 'native Backups retention modal persists authorized daily/weekly/timezone settings');
rejected(fn () => (require dirname(__DIR__).'/database/migrations/2026_10_15_000003_create_ibyte_restic_retention.php')->down(), 'uninstall cannot discard saved retention policies', 'Cannot discard saved');

// Calendar semantics, including disjoint representatives and locked quota usage.
$point = fn ($id, $time, $locked = false) => (new Backup)->forceFill(['id' => $id, 'created_at' => $time, 'completed_at' => $time, 'is_successful' => true, 'is_locked' => $locked]);
$calendar = collect([
    $point(1, '2026-10-01 12:00:00', true), $point(2, '2026-10-04 12:00:00'),
    $point(3, '2026-10-11 12:00:00'), $point(4, '2026-10-14 12:00:00'),
    $point(5, '2026-10-15 13:00:00'), $point(6, '2026-10-15 14:00:00'),
    $point(7, '2026-10-15 14:30:00'),
]);
$utcPolicy = ['enabled' => true, 'daily' => 1, 'weekly' => 1, 'timezone' => 'UTC'];
$kept = Retention::keep($calendar, $utcPolicy, 5, CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
check($kept->sort()->values()->all() === [1, 3, 4, 5, 7], 'calendar planner keeps locked plus latest completed week/day and newest distinct hourly representatives');
check(Retention::keep($calendar, $utcPolicy, 1)->all() === [1], 'locked copies consume all quota before any calendar reservation');
check(Retention::keep($calendar, $utcPolicy, 2)->sort()->values()->all() === [1, 7], 'newest recovery point takes priority over older tiers when locks consume quota');
$dst = collect([$point(8, '2026-11-01 06:30:00'), $point(9, '2026-11-01 07:30:00')]);
check(Retention::keep($dst, ['enabled' => true, 'daily' => 0, 'weekly' => 0, 'timezone' => 'America/Chicago'], 2)->count() === 2, 'repeated DST hour keeps distinct UTC-offset buckets');

// Execute background retention against native delete and confirmed node results.
// These fixtures simulate existing successful snapshots at known calendar times.
foreach (['2026-10-14 13:00:00', '2026-10-15 12:01:00', '2026-10-15 12:30:00'] as $time) {
    $b = Backup::factory()->create(['server_id' => $retentionServer->id, 'backup_host_id' => $retentionHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => $time]);
    DB::table('backups')->where('id', $b->id)->update(['created_at' => $time]);
}
$forgetBeforeRollup = count($forgotten);
(new ApplyRetention($retentionServer->uuid))->handle();
check(count($forgotten) === $forgetBeforeRollup + 1 && $secondRetention->fresh()->is_locked, 'retention worker forgets one redundant snapshot per bounded queue turn without touching locks');
check(DB::table('ibyte_restic_retention')->where('server_uuid', $retentionServer->uuid)->value('last_run_at') !== null, 'retention worker records successful maintenance time');
$extraRollup = Backup::factory()->create(['server_id' => $retentionServer->id, 'backup_host_id' => $retentionHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => '2026-10-15 12:45:00']);
DB::table('backups')->where('id', $extraRollup->id)->update(['created_at' => '2026-10-15 12:45:00']);
$forgetMode = 'failed';
$beforeFailedRollup = $retentionServer->backups()->count();
rejected(fn () => (new ApplyRetention($retentionServer->uuid))->handle(), 'failed background retention remains retryable without fake completion', 'Snapshot deletion was not confirmed');
check($retentionServer->backups()->count() === $beforeFailedRollup && Retention::policy($retentionServer)['last_error'] !== null, 'background retention failure preserves metadata and reports a cached error');
$forgetMode = 'succeeded';
rejected(fn () => HealthActions::queue($retentionServer->user, $retentionHost, 'prune'), 'repository prune control is root-admin only');
Retention::save($retentionServer->user, $retentionServer, array_merge($policyData, ['enabled' => false]));
// Fill the exact requested 20-slot example, keeping the oldest locked copy.
while ($retentionServer->backups()->nonFailed()->count() < 20) {
    Backup::factory()->create(['server_id' => $retentionServer->id, 'backup_host_id' => $retentionHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => now()]);
}
$oldestTwenty = $retentionServer->backups()->nonFailed()->where('is_locked', false)->whereNotNull('completed_at')->orderBy('created_at')->orderBy('id')->firstOrFail();
$createOnly = User::factory()->create();
Subuser::factory()->create(['server_id' => $retentionServer->id, 'user_id' => $createOnly->id, 'permissions' => ['backup.read', 'backup.create']]);
Auth::login($createOnly);
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer), 'create-only subuser cannot cause quota deletion', 'delete permission');
rejected(fn () => Retention::save($createOnly, $retentionServer, $policyData), 'create-only subuser cannot change automatic deletion policy');
Auth::login($retentionServer->user);
$twentyFirst = app(InitiateBackupService::class)->handle($retentionServer, '21st backup replaces oldest');
check($retentionServer->backups()->nonFailed()->count() === 20 && $oldestTwenty->fresh() === null && $secondRetention->fresh()->is_locked && ! $twentyFirst->completed_at, '21st backup automatically replaces oldest unlocked snapshot while total remains 20');
Retention::save($retentionServer->user, $retentionServer, $policyData);
(new ApplyRetention($retentionServer->uuid))->handle();
check($retentionServer->backups()->nonFailed()->count() === 20, 'older queued retention work skips removal while a newer backup is pending');
$beforeUnlinked = $retentionServer->backups()->count();
$retentionHost->nodes()->detach($retentionServer->node_id);
$callsBeforeUnlinked = count(Http::recorded());
Livewire::test(ListBackups::class)->assertStatus(200)->assertCanSeeTableRecords([$secondRetention]);
check(count(Http::recorded()) === $callsBeforeUnlinked, 'native backup list renders successful backups with missing node link without node calls or HTTP 500');
$unlinkedUrl = app(ResticSchema::class)->getDownloadLink($secondRetention, $retentionServer->user);
$unlinkedRequest = Request::create($unlinkedUrl);
$unlinkedRequest->setUserResolver(fn () => $retentionServer->user);
try {
    app(DownloadController::class)($unlinkedRequest, $secondRetention, app(Transport::class));
    throw new RuntimeException('Unlinked download unexpectedly accepted.');
} catch (HttpException $exception) {
    check($exception->getStatusCode() === 409 && str_contains($exception->getMessage(), 'Linked Nodes'), 'unlinked download remains denied with actionable conflict instead of bypassing isolation');
}
check(count(Http::recorded()) === $callsBeforeUnlinked && $retentionServer->backups()->count() === $beforeUnlinked, 'rendering and denied download preserve every backup and make no daemon call');
$retentionHost->nodes()->attach($retentionServer->node_id);
Livewire::test(ListBackups::class)->assertStatus(200)->assertCanSeeTableRecords([$secondRetention]);
check($retentionServer->backups()->count() === $beforeUnlinked, 'restoring the original explicit host-node association preserves native backup records');
// Lowering quota never mass-deletes snapshots as a side effect of create.
$retentionServer->update(['backup_limit' => 10]);
rejected(fn () => app(InitiateBackupService::class)->handle($retentionServer), 'lowered quota below usage requires explicit excess removal', 'exceeds the new limit');
check($retentionServer->backups()->nonFailed()->count() === 20, 'over-limit refusal preserves every existing record');
Auth::logout();
Filament::setTenant(null, isQuiet: true);

// Non-Restic service semantics must remain native even with override binding.
$stockServer = Server::factory()->withNode()->create(['backup_limit' => 1]);
$stockHost = BackupHost::create(['name' => 'Stock quota fixture', 'schema' => 'wings', 'configuration' => []]);
$stockHost->nodes()->attach($stockServer->node_id);
Backup::factory()->create(['server_id' => $stockServer->id, 'backup_host_id' => $stockHost->id, 'is_successful' => true, 'completed_at' => now()]);
rejected(fn () => app(InitiateBackupService::class)->handle($stockServer), 'Wings manual quota remains native with no automatic replacement', 'reached its limit');
rejected(fn () => Retention::save($stockServer->user, $stockServer, $policyData), 'retention settings cannot apply to a non-Restic server');

// The native import/reinstall route is an update; uninstall is neither required
// nor safe while repositories/history exist. Exercise the real reinstall job.
Filament::setCurrentPanel(Filament::getPanel('admin'));
Auth::login($formAdmin);
$preservedTables = ['backup_hosts', 'backup_host_node', 'backups', 'ibyte_restic_operations', 'ibyte_restic_retention', 'ibyte_restic_repositories', 'ibyte_restic_recovery_snapshots'];
$updateInventory = fn () => array_map(fn ($table) => hash('sha256', json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR)), $preservedTables);
$beforeInPlace = $updateInventory();
$plugins->downloadPluginFromFile(new UploadedFile($root.'/release/Panel Plugin/'.$panelZipName, 'update-with-live-backups.zip', 'application/zip', null, true));
Plugin::refreshRows();
(new InstallPlugin($formAdmin, 'ibyte-restic', reinstall: true))->handle($plugins);
check(Plugin::findOrFail('ibyte-restic')->status === PluginStatus::Enabled && $beforeInPlace === $updateInventory(), 'native in-place ZIP re-import and actual reinstall job preserve hosts/node links/backups/locks/operations/policies/encrypted recovery inventory');
