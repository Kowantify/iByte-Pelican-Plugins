<?php

use App\Filament\Admin\Resources\BackupHosts\Pages\ViewBackupHost;
use App\Filament\Admin\Resources\BackupHosts\RelationManagers\BackupsRelationManager;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Admin\RepositoryStatistics;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Integration\LifecycleGuard;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Storage\StorageReport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

$accountServer = Server::factory()->withNode()->create(['backup_limit' => 10]);
$accountHost = BackupHost::create(['name' => 'Storage fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'test', 'backend' => 'local', 'staging_enabled' => true, 'restore_fixture_enabled' => false]]);
$accountHost->nodes()->attach($accountServer->node_id);
$accountBackup = Backup::factory()->create(['server_id' => $accountServer->id, 'backup_host_id' => $accountHost->id, 'is_successful' => false, 'completed_at' => null]);
$storage = ['total_bytes_processed' => 4 * 1024 ** 3, 'data_added' => 1024 ** 2, 'data_added_packed' => 2048];
$repository = ['total_size' => 4096, 'total_uncompressed_size' => 1024 ** 2, 'logical_bytes' => 8 * 1024 ** 3, 'snapshots_count' => 2, 'compression_ratio' => 256.0, 'compression_space_saving' => 99.6, 'repository_file_bytes' => 16384, 'snapshots' => [$accountBackup->uuid => $storage]];
$badMetrics = false;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($accountServer, $storage, &$repository, &$badMetrics) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$accountServer->node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['test' => 'local']], 200, $headers);
    }
    $payload = $request->data();
    if (str_ends_with($request->url(), '/recovery')) {
        return Http::response(fixtureRecovery($payload), 200, $headers);
    }

    return Http::response($payload + ['status' => 'succeeded', 'snapshot' => str_repeat('c', 64), 'bytes' => $storage['total_bytes_processed'], 'storage' => $badMetrics ? ['data_added_packed' => -1] : $storage, 'repository' => $repository], 200, $headers);
});
app(Operations::class)->queue($accountBackup, 'create', $accountBackup->uuid);
(new PollOperation($accountBackup->uuid))->handle(app(Transport::class));
$accountBackup->refresh();
check($accountBackup->is_successful && $accountBackup->bytes === 2048, 'native bytes records newly added compressed data, not full logical game files');
check(StorageReport::snapshot($accountBackup) === $storage && str_contains(StorageReport::tooltip($accountBackup), 'logical restore size'), 'durable per-snapshot accounting preserves logical restore size and explains shared chunks');
check(! StorageReport::validMetrics(['storage' => ['total_bytes_processed' => 5, 'data_added' => 0, 'data_added_packed' => -1]]), 'negative/untrusted storage counters rejected');
check(StorageReport::validMetrics(['storage' => ['total_bytes_processed' => 5, 'data_added' => 0, 'data_added_packed' => 0]]), 'fully deduplicated zero added bytes accepted');

$statsRequest = (string) Str::uuid();
app(Operations::class)->queue($accountBackup, 'stats', $statsRequest);
(new PollOperation($statsRequest))->handle(app(Transport::class));
$report = StorageReport::summary($accountHost);
check(str_contains($report, 'dedup estimate 8,192.00x') && str_contains($report, 'compression 256.00x') && str_contains($report, 'repository files') && str_contains($report, 'measured'), 'admin report includes cached dedup/compression, actual local file bytes and measurement time');
$sentBefore = count(Http::recorded());
StorageReport::summary($accountHost);
StorageReport::size($accountBackup);
check(count(Http::recorded()) === $sentBefore, 'admin/customer storage rendering performs no node calls');

// Removing create counters simulates older installed backups: refresh stats backfills
// from the immutable snapshot summary without inventing sizes from logical bytes.
DB::table('ibyte_restic_operations')->where('request_uuid', $accountBackup->uuid)->update(['metrics' => null]);
check(StorageReport::size($accountBackup) === convert_bytes_to_readable(2048), 'stats refresh recovers historical snapshot additions for older backups');
$unknown = Backup::factory()->create(['server_id' => $accountServer->id, 'backup_host_id' => $accountHost->id, 'bytes' => 4 * 1024 ** 3]);
check(StorageReport::size($unknown) === 'Unknown', 'legacy logical bytes are never displayed as added storage');
$zero = $storage;
$zero['data_added_packed'] = 0;
DB::table('ibyte_restic_operations')->where('request_uuid', $accountBackup->uuid)->update(['metrics' => json_encode(['storage' => $zero])]);
DB::table('ibyte_restic_operations')->where('request_uuid', $statsRequest)->update(['metrics' => json_encode(['repository' => array_merge($repository, ['snapshots' => [$accountBackup->uuid => $zero]])])]);
check(StorageReport::size($accountBackup) === convert_bytes_to_readable(0), 'zero added bytes renders explicitly instead of an empty cell');

$adminUser = $accountServer->user;
auth()->login($adminUser);
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));
Filament\Facades\Filament::setTenant($accountServer);
$tablePage = Livewire::test(ListBackups::class);
$sizeColumn = $tablePage->instance()->getTable()->getColumn('bytes')->record($accountBackup);
check($sizeColumn->formatState($sizeColumn->getState()) === convert_bytes_to_readable(0) && str_contains($sizeColumn->getTooltip(), 'Shared chunks'), 'actual native customer table renders deduplicated size and explanatory tooltip');
$ordinaryHost = BackupHost::create(['name' => 'Ordinary size', 'schema' => 'wings', 'configuration' => []]);
$ordinary = Backup::factory()->create(['server_id' => $accountServer->id, 'backup_host_id' => $ordinaryHost->id, 'bytes' => 10000]);
check($sizeColumn->record($ordinary)->formatState($ordinary->bytes) === convert_bytes_to_readable(10000) && $sizeColumn->getTooltip() === null, 'native Wings backup size semantics remain unchanged');
$ordinary->setRelation('backupHost', new BackupHost(['schema' => 's3']));
check($sizeColumn->record($ordinary)->formatState($ordinary->bytes) === convert_bytes_to_readable(10000), 'native S3 backup size semantics remain unchanged');

$foreignServer = Server::factory()->withNode()->create();
$foreignBackup = Backup::factory()->create(['server_id' => $foreignServer->id, 'backup_host_id' => $accountHost->id, 'bytes' => 777]);
$repository['snapshots'][$foreignBackup->uuid] = $storage;
$repository['snapshots'][$ordinary->uuid] = $storage;
$scopedStats = (string) Str::uuid();
app(Operations::class)->queue($accountBackup, 'stats', $scopedStats);
(new PollOperation($scopedStats))->handle(app(Transport::class));
check($foreignBackup->fresh()->bytes === 777 && $ordinary->fresh()->bytes === 10000, 'node stats cannot overwrite sizes across server or backup-host identity');
$invalidBackup = Backup::factory()->create(['server_id' => $accountServer->id, 'backup_host_id' => $accountHost->id, 'is_successful' => false, 'completed_at' => null]);
app(Operations::class)->queue($invalidBackup, 'create', $invalidBackup->uuid);
$badMetrics = true;
(new PollOperation($invalidBackup->uuid))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $invalidBackup->uuid)->value('status') === 'unknown' && ! $invalidBackup->fresh()->completed_at, 'malformed node metrics fail actual completion closed rather than marking success');
$badMetrics = false;

check(app(ServerDeletionService::class) instanceof GuardedServerDeletion, 'native server deletion resolves early Restic safety guard');
$sentBefore = count(Http::recorded());
rejected(fn () => app(ServerDeletionService::class)->withForce()->handle($accountServer), 'forced native server deletion blocked before daemon data removal', 'Restic blocks');
check(count(Http::recorded()) === $sentBefore && $accountServer->fresh() !== null, 'blocked native deletion performs no daemon call and preserves server');
rejected(fn () => ServerTransfer::create(['server_id' => $accountServer->id, 'old_node' => $accountServer->node_id, 'new_node' => $otherNode->id]), 'native transfer record blocked before notifications while Restic backups remain', 'Restic blocks');
check(! ServerTransfer::where('server_id', $accountServer->id)->exists(), 'blocked transfer leaves no pending transfer record');
$plainServer = Server::factory()->withNode()->create();
LifecycleGuard::assertClear($plainServer);
$plainTransfer = ServerTransfer::create(['server_id' => $plainServer->id, 'old_node' => $plainServer->node_id, 'new_node' => $otherNode->id]);
check($plainTransfer->exists, 'non-Restic server transfer model path remains available');
$clearServer = Server::factory()->withNode()->create();
$daemonMock = Mockery::mock(DaemonServerRepository::class);
$daemonMock->shouldReceive('setServer')->once()->withArgs(fn ($candidate) => $candidate->is($clearServer))->andReturnSelf();
$daemonMock->shouldReceive('delete')->once()->andReturnNull();
app()->instance(DaemonServerRepository::class, $daemonMock);
app(ServerDeletionService::class)->handle($clearServer);
check($clearServer->fresh() === null, 'clear non-Restic server deletion delegates to original native service with mocked daemon');
Mockery::close();
app()->forgetInstance(DaemonServerRepository::class);
$request = (string) Str::uuid();
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $request, 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $plainServer->uuid, 'node_id' => $plainServer->node_id, 'host_id' => $accountHost->id, 'operation' => 'create', 'status' => 'unknown', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
rejected(fn () => LifecycleGuard::assertClear($plainServer), 'orphaned unresolved operation blocks deletion/transfer until reconciled', 'Restic blocks');

Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
Filament\Facades\Filament::setTenant(null);
auth()->login($formAdmin);
$adminStorage = Livewire::test(RepositoryStatistics::class);
check(str_contains($adminStorage->html(), '256.00x') && str_contains($adminStorage->html(), 'Measured at'), 'native admin Stats tab renders cached repository ratios and measurement dates');
$adminOverview = Livewire::test(ViewBackupHost::class, ['record' => $accountHost->id])
    ->assertActionExists('ibyte-restic-overview');
check(str_contains($adminOverview->html(), 'Restic Backups') && ! str_contains($adminOverview->html(), 'Capture recovery keys and owner inventory'), 'native host offers one Restic Backups link and links to unified admin area without a manual key capture action');
$adminRelation = Livewire::test(BackupsRelationManager::class, ['ownerRecord' => $accountHost, 'pageClass' => ViewBackupHost::class]);
$adminSize = $adminRelation->instance()->getTable()->getColumn('bytes')->record($accountBackup);
check($adminSize->formatState($accountBackup->bytes) === convert_bytes_to_readable(0) && str_contains($adminSize->getTooltip(), 'logical restore size'), 'actual native admin backup relation displays added storage including zero with logical size tooltip');
