<?php

use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ServerDeletionService;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryColumns;
use IByte\Restic\Jobs\DestroyServerRepositories;
use IByte\Restic\Recovery\ForcedRepositoryRemoval;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\ServerDestruction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

auth()->login($formAdmin);
$formAdmin->update(['password' => Hash::make('destroy-fixture')]);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
config(['queue.default' => 'database']);
$destroyHost = BackupHost::create(['name' => 'Destruction fixture', 'schema' => 'restic', 'configuration' => ['backend' => 'local', 'profile' => 'default', 'staging_enabled' => false]]);
$makeDestroyFixture = function () use ($legacyRepo, $destroyHost) {
    $server = Server::factory()->withNode()->create(['backup_limit' => 20]);
    $server->node->update(['scheme' => 'https']);
    $repo = $legacyRepo->replicate();
    $repo->fill(['host_id' => $destroyHost->id, 'node_id' => $server->node_id, 'server_uuid' => $server->uuid, 'profile' => 'default', 'backend' => 'local', 'server_deleted_at' => null,
        'metadata' => ['repository' => '/var/lib/ibyte-restic/repositories/host-'.$destroyHost->id.'/node-'.$server->node_id.'/'.$server->uuid]])->save();
    $backup = Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $destroyHost->id, 'is_locked' => true, 'is_successful' => false, 'completed_at' => null]);

    return [$server, $repo, $backup];
};
[$destroyServer, $destroyRepo, $destroyBackup] = $makeDestroyFixture();
[$otherDestroyServer, $otherDestroyRepo] = $makeDestroyFixture();
$modal = Livewire::test(EditServer::class, ['record' => $destroyServer->getRouteKey()])->mountAction('Delete')->assertStatus(200);
$modalData = $modal->get('mountedActions')[0]['data'];
check(isset($modalData['restic_delete_mode']) && $modalData['restic_delete_mode'] === 'retain' && ! array_key_exists('restic_server_uuid', $modalData), 'native Delete defaults to retaining a final backup and has no UUID input');
check(str_contains($modal->getMountedActionModalHtml(), 'Force delete server') && str_contains($modal->getMountedActionModalHtml(), 'Current Panel password'), 'native Delete renders the destructive alternative and current-password control');
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::failedConnection());
$beforeDestructionRows = DB::table('ibyte_restic_operations')->count();
Livewire::test(EditServer::class, ['record' => $destroyServer->getRouteKey()])->callAction('Delete', data: ['restic_delete_mode' => 'destroy', 'restic_password' => 'wrong'])->assertHasActionErrors(['restic_password']);
check($destroyServer->fresh() !== null && DB::table('ibyte_restic_operations')->count() === $beforeDestructionRows && Http::recorded()->isEmpty(), 'wrong password cannot persist a destruction intent or contact the node');
rejected(fn () => app(ServerDestruction::class)->run($destroyServer->user, $destroyServer, 'destroy-fixture'), 'server owner cannot invoke root repository destruction');
rejected(fn () => app(ServerDeletionService::class)->withForce()->handle($destroyServer), 'ordinary native force deletion still requires explicit Restic authorization');

// Include a second original node retained after a historical transfer.
$archiveNode = Node::factory()->create(['scheme' => 'https']);
$archiveRepo = $destroyRepo->replicate();
$archiveRepo->fill(['node_id' => $archiveNode->id, 'metadata' => ['repository' => '/var/lib/ibyte-restic/repositories/host-'.$destroyHost->id.'/node-'.$archiveNode->id.'/'.$destroyServer->uuid,
    'archived_at' => now()->toIso8601String(), 'automatic_maintenance' => true]])->save();
$secrets = [$destroyRepo->id => $destroyRepo->getRawOriginal('secret'), $archiveRepo->id => $archiveRepo->getRawOriginal('secret')];
$restoreUuid = (string) Str::uuid();
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $restoreUuid, 'backup_uuid' => $destroyBackup->uuid, 'host_id' => $destroyHost->id, 'node_id' => $destroyServer->node_id,
    'server_uuid' => $destroyServer->uuid, 'operation' => 'restore', 'status' => 'unknown', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
$nativeDestroy = Mockery::mock(DaemonServerRepository::class);
$nativeDestroy->shouldReceive('setServer')->andReturnSelf();
$nativeDestroy->shouldReceive('delete')->once()->andReturnUsing(function () use ($destroyServer, $otherDestroyServer) {
    $intent = DB::table('ibyte_restic_operations')->where('server_uuid', $destroyServer->uuid)->where('operation', 'lifecycle')->where('status', 'reserved')->first();
    check($intent !== null && DB::transactionLevel() === 0 && count(json_decode($intent->payload, true)['repositories']) === 2, 'both original repository targets commit before native force deletion contacts Wings');
    check(app(ServerDestruction::class)->active($destroyServer) && ! app(ServerDestruction::class)->active($otherDestroyServer), 'destruction bypass is limited to the confirmed server and node');
    throw new ConnectionException('Disposable node offline');
});
app()->instance(DaemonServerRepository::class, $nativeDestroy);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(EditServer::class, ['record' => $destroyServer->getRouteKey()])->callAction('Delete', data: ['restic_delete_mode' => 'destroy', 'restic_password' => 'destroy-fixture'])->assertRedirect();
$destroyIntent = DB::table('ibyte_restic_operations')->where('server_uuid', $destroyServer->uuid)->where('operation', 'lifecycle')->first();
check($destroyServer->fresh() === null && $destroyIntent->status === 'queued', 'password-only destructive native action force-deletes the server despite offline Wings and locked pending backups');
check(Http::recorded()->isEmpty() && ! DB::table('ibyte_restic_operations')->where('server_uuid', $destroyServer->uuid)->whereIn('operation', ['create', 'final-backup'])->exists(), 'destructive mode fetches no escrow and creates no final backup or snapshot delete requests');
check(! str_contains($destroyIntent->payload, 'destroy-fixture') && ! str_contains($destroyIntent->payload, 'password'), 'committed cleanup plan contains no administrator password');
check(! app(ServerDestruction::class)->active($destroyServer) && $otherDestroyServer->fresh() !== null, 'native deletion scope is cleared and neighboring server remains');
check(DB::table('jobs')->where('payload', 'like', '%DestroyServerRepositories%')->where('payload', 'like', '%'.$destroyIntent->request_uuid.'%')->exists(), 'cleanup job is durably serialized in the actual database queue after native server deletion');

$cleanup = new DestroyServerRepositories($destroyIntent->request_uuid);
$cleanup->handle(app(ForcedRepositoryRemoval::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $destroyIntent->request_uuid)->value('status') === 'unknown' && ! isset($destroyRepo->fresh()->metadata['repository_deleted_at']), 'offline cleanup remains visibly unverified without falsely marking repository deletion');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $restoreUuid)->value('status') === 'unknown', 'server force deletion does not erase the original unresolved restore journal evidence');
$seenCleanup = [];
$cleanupMode = 'forged';
$sourceNodes = [$destroyServer->node, $archiveNode];
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($sourceNodes, &$seenCleanup, &$cleanupMode) {
    $node = collect($sourceNodes)->first(fn ($node) => str_starts_with($request->url(), $node->getConnectionAddress()));
    if (! $node) {
        throw new RuntimeException('Unexpected fixture node');
    }
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'force_repository_delete' => 'local-v1', 'profiles' => ['default' => 'local']], 200, $headers);
    }
    check(str_ends_with($request->url(), '/repository-delete'), 'background destruction uses only the original daemon-authenticated repository-delete route');
    $payload = $request->data();
    $seenCleanup[] = $payload;
    if ($cleanupMode === 'forged') {
        $payload['generation'] = 91;
    }

    return Http::response($payload + ['status' => 'succeeded'], 200, $headers);
});
$cleanup->handle(app(ForcedRepositoryRemoval::class));
check(! isset($destroyRepo->fresh()->metadata['repository_deleted_at']), 'forged background deletion reply cannot retire the repository');
$cleanupMode = 'success';
$cleanup->handle(app(ForcedRepositoryRemoval::class));
check(isset($destroyRepo->fresh()->metadata['repository_deleted_at']) && ! isset($archiveRepo->fresh()->metadata['repository_deleted_at']), 'one queue invocation deletes only one verified original repository');
$cleanup->handle(app(ForcedRepositoryRemoval::class));
check(isset($archiveRepo->fresh()->metadata['repository_deleted_at']) && DB::table('ibyte_restic_operations')->where('request_uuid', $destroyIntent->request_uuid)->value('status') === 'succeeded', 'next invocation advances past the completed target and removes the retained source repository too');
check(count(array_unique(array_column(array_filter($seenCleanup, fn ($entry) => $entry['node'] === $destroyServer->node_id), 'request_id'))) === 1, 'background retry reuses the persisted per-repository destructive request ID');
foreach ($secrets as $id => $secret) {
    check(RecoveryRepository::find($id)->getRawOriginal('secret') === $secret, 'physical deletion preserves encrypted recovery history for repository '.$id);
}
$afterCleanupRequests = count(Http::recorded());
$cleanup->handle(app(ForcedRepositoryRemoval::class));
check(count(Http::recorded()) === $afterCleanupRequests && ! isset($otherDestroyRepo->fresh()->metadata['repository_deleted_at']), 'completed cleanup replay makes no request and never touches a neighboring repository');

// A plan cannot be converted into an arbitrary current repository deletion.
[$identityServer, $identityRepo] = $makeDestroyFixture();
rejected(fn () => app(ForcedRepositoryRemoval::class)->resumeServerDestruction($formAdmin, $identityRepo, $destroyIntent->request_uuid), 'cleanup refuses a repository outside the immutable confirmed plan');
$identityRepo->update(['backend' => 's3']);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(ServerDestruction::class)->run($formAdmin, $identityServer, 'destroy-fixture'), 'unsupported remote physical deletion blocks before deleting the server', 'S3');
check($identityServer->fresh() !== null, 'unsupported cleanup preserves the native server');

// Failed native deletion leaves the durable intent, never runs cleanup on a
// live server, and an authenticated retry reuses that exact intent.
[$retryDestroyServer, $retryDestroyRepo] = $makeDestroyFixture();
$nativeRetry = Mockery::mock(DaemonServerRepository::class);
$nativeRetry->shouldReceive('setServer')->andReturnSelf();
$nativeRetry->shouldReceive('delete')->once()->andThrow(new Error('Injected native deletion interruption'));
app()->instance(DaemonServerRepository::class, $nativeRetry);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(ServerDestruction::class)->run($formAdmin, $retryDestroyServer, 'destroy-fixture'), 'native fatal interruption preserves cleanup intent without deleting repositories');
$retryIntent = DB::table('ibyte_restic_operations')->where('server_uuid', $retryDestroyServer->uuid)->where('operation', 'lifecycle')->first();
check($retryIntent->status === 'reserved' && ! app(ServerDestruction::class)->active($retryDestroyServer), 'interrupted native call preserves reservation and clears in-process bypass');
$beforeLiveReplay = count(Http::recorded());
(new DestroyServerRepositories($retryIntent->request_uuid))->handle(app(ForcedRepositoryRemoval::class));
check(count(Http::recorded()) === $beforeLiveReplay && $retryDestroyServer->fresh() !== null, 'background cleanup cannot run while the native server still exists');
$nativeRetry = Mockery::mock(DaemonServerRepository::class);
$nativeRetry->shouldReceive('setServer')->andReturnSelf();
$nativeRetry->shouldReceive('delete')->once()->andReturnNull();
app()->instance(DaemonServerRepository::class, $nativeRetry);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$retryRequest = app(ServerDestruction::class)->run($formAdmin, $retryDestroyServer->fresh(), 'destroy-fixture');
check($retryRequest === $retryIntent->request_uuid && $retryDestroyServer->fresh() === null, 'reauthenticated native retry completes with the original immutable destruction intent');

$beforeChangedTarget = count(Http::recorded());
$savedMetadata = $retryDestroyRepo->fresh()->metadata;
$retryDestroyRepo->update(['metadata' => array_merge($savedMetadata, ['generation' => 7])]);
(new DestroyServerRepositories($retryRequest))->handle(app(ForcedRepositoryRemoval::class));
check(count(Http::recorded()) === $beforeChangedTarget && ! isset($retryDestroyRepo->fresh()->metadata['repository_deleted_at']), 'changed repository generation cannot redirect an already authorized background destruction');
$retryDestroyRepo->update(['metadata' => $savedMetadata]);
// Simulate a process dying after native commit but before the dispatch update.
DB::table('ibyte_restic_operations')->where('request_uuid', $retryRequest)->update(['status' => 'reserved']);
ServerDestruction::dispatchPending();
check(DB::table('ibyte_restic_operations')->where('request_uuid', $retryRequest)->value('status') === 'queued', 'scheduler recovers the crash window after native deletion and before cleanup dispatch');
check(RepositoryColumns::stateLabel($retryDestroyRepo->fresh()) === 'Destruction pending', 'admin repository state distinguishes requested destruction from intentionally retained storage');
