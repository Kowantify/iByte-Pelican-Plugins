<?php

use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use Filament\Facades\Filament;
use IByte\Restic\Admin\ListRecoveryRepositories;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Jobs\ApplyRetention;
use IByte\Restic\Jobs\CaptureRecovery;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\Maintenance;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

Bus::fake([CaptureRecovery::class, PollOperation::class, ApplyRetention::class]);
Http::swap(new Factory);
Http::preventStrayRequests();
$autoServer = Server::factory()->withNode()->create(['backup_limit' => 5]);
$autoHost = BackupHost::create(['name' => 'Automatic metrics fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
$autoHost->nodes()->attach($autoServer->node_id);
$autoStats = ['total_size' => 120, 'total_uncompressed_size' => 240, 'logical_bytes' => 960, 'snapshots_count' => 1, 'compression_ratio' => 2, 'compression_space_saving' => 50, 'repository_file_bytes' => 360, 'snapshots' => []];
$accountingUnavailable = false;
Http::fake(function ($request) use ($autoServer, $autoStats, &$accountingUnavailable) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$autoServer->node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']], 200, $headers);
    }
    $payload = $request->data();
    if (str_ends_with($request->url(), '/recovery')) {
        return Http::response(fixtureRecovery($payload), 200, $headers);
    }

    $accounting = $accountingUnavailable ? ['maintenance_error' => 'fixture accounting failure'] : ['repository' => $autoStats];

    return Http::response($payload + ['status' => 'succeeded', 'snapshot' => str_repeat('f', 64), 'bytes' => 960, 'storage' => ['total_bytes_processed' => 960, 'data_added' => 240, 'data_added_packed' => 120]] + $accounting, 200, $headers);
});
$autoBackup = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($autoServer, 'Automatic key and storage capture');
$autoRepo = RecoveryRepository::where('server_uuid', $autoServer->uuid)->firstOrFail();
check(! $autoBackup->completed_at && ! str_contains($autoRepo->getRawOriginal('secret'), 'disposable-test-password'), 'new backups automatically escrow encrypted keys before dispatch without a capture button');
(new PollOperation($autoBackup->uuid))->handle(app(Transport::class));
$autoBackup->refresh();
$measurement = StorageReport::repository($autoRepo);
check($autoBackup->is_successful && json_decode($measurement->metrics, true)['repository']['repository_file_bytes'] === 360, 'native completion stores repository measurements supplied by successful backup');
check(str_contains(StorageReport::summary($autoHost), 'dedup estimate 4.00x'), 'host statistics use automatically captured create metrics without a manual stats operation');
check(str_contains(StorageReport::serverSummary($autoServer), 'dedup estimate 4x'), 'customer storage reads the same persisted automatic statistics');

// Simulate an existing native backup that predates automatic escrow.
DB::table('ibyte_restic_repositories')->where('id', $autoRepo->id)->update(['captured_at' => now()->subHours(2)]);
Maintenance::dispatch();
Bus::assertDispatched(CaptureRecovery::class, fn ($job) => $job->backupUuid === $autoBackup->uuid);
check(true, 'existing repositories are selected automatically for encrypted capture and statistics synchronization');
(new CaptureRecovery($autoBackup->uuid))->handle();
check($autoRepo->fresh()->captured_at->greaterThan(now()->subMinute()), 'background synchronization refreshes escrow without an administrator action');
$automaticStats = DB::table('ibyte_restic_operations')->where('server_uuid', $autoServer->uuid)->where('operation', 'stats')->firstOrFail();
check($automaticStats->status === 'queued', 'automatic synchronization queues bounded statistics refresh');
(new CaptureRecovery($autoBackup->uuid))->handle();
check(DB::table('ibyte_restic_operations')->where('server_uuid', $autoServer->uuid)->where('operation', 'stats')->count() === 1, 'automatic synchronization coalesces pending statistics work');

Filament::setCurrentPanel(Filament::getPanel('server'));
auth()->login($autoServer->user);
Filament::setTenant($autoServer);
$beforeCached = count(Http::recorded());
Livewire::test(ListBackups::class)->mountTableAction('ibyte-restic-storage')->assertTableActionMounted('ibyte-restic-storage');
check(count(Http::recorded()) === $beforeCached, 'native customer storage interface displays cached data without contacting a node');
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));
auth()->login($formAdmin);
Livewire::test(ListRecoveryRepositories::class)->assertStatus(200)->assertCanSeeTableRecords([$autoRepo]);
check(RecoveryResource::getNavigationLabel() === 'Restic Backups' && str_contains(RecoveryResource::getUrl(panel: 'admin'), '/restic-backups'), 'keys, repository identity and storage share one Restic Backups admin area');
check(count(Http::recorded()) === $beforeCached, 'unified admin overview uses cached keys and statistics without node requests');
$events = app(Schedule::class)->events();
check(collect($events)->contains(fn ($event) => $event->description === 'ibyte-restic-repository-maintenance'), 'automatic repository maintenance uses the existing native scheduler');
// A newer pending backup must not prevent statistics on an older completed one.
(new PollOperation($automaticStats->request_uuid))->handle(app(Transport::class));
$autoPending = Backup::factory()->create(['server_id' => $autoServer->id, 'backup_host_id' => $autoHost->id, 'is_successful' => false, 'completed_at' => null]);
(new CaptureRecovery($autoPending->uuid))->handle();
check(DB::table('ibyte_restic_operations')->where('server_uuid', $autoServer->uuid)->where('operation', 'stats')->where('status', 'queued')->where('backup_uuid', $autoBackup->uuid)->exists(), 'pending newer backup does not block automatic statistics for the latest completed snapshot');

// A valid snapshot with failed accounting remains successful and reports a warning.
$accountingUnavailable = true;
$warningBackup = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($autoServer, 'Accounting warning fixture');
(new PollOperation($warningBackup->uuid))->handle(app(Transport::class));
$warningOperation = DB::table('ibyte_restic_operations')->where('request_uuid', $warningBackup->uuid)->firstOrFail();
check($warningBackup->fresh()->is_successful && str_contains($warningOperation->error, 'statistics will retry automatically'), 'accounting failure preserves verified backup success and records an explicit maintenance warning');
check(! isset(json_decode($warningOperation->metrics, true)['repository']), 'unavailable repository measurements are never invented or saved as zero');

auth()->logout();
