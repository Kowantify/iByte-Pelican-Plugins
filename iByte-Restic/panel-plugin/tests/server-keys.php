<?php

use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Http\Controllers\Api\Remote\Servers\ServerTransferController;
use App\Models\Allocation;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\InitiateBackupService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Recovery\RetainedDeletion;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

auth()->login($formAdmin);
$formAdmin->update(['password' => Hash::make('server-key-fixture')]);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
$lifecycleServer = Server::factory()->withNode()->create(['backup_limit' => 20, 'status' => null]);
$lifecycleHost = BackupHost::create(['name' => 'Lifecycle keys fixture', 'schema' => 'restic', 'configuration' => ['backend' => 'local', 'profile' => 'test', 'staging_enabled' => true]]);
$lifecycleHost->nodes()->attach($lifecycleServer->node_id);
$backups = collect();
for ($i = 0; $i < 3; $i++) {
    $backups->push(Backup::factory()->create(['server_id' => $lifecycleServer->id, 'backup_host_id' => $lifecycleHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => now(), 'created_at' => now()->subMinutes(3 - $i)]));
}
$prepared = false;
$finalized = false;
$deletes = [];
$proofMissing = false;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use (&$lifecycleServer, &$prepared, &$finalized, &$deletes, &$proofMissing) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$lifecycleServer->node->daemon_token_id.')'];
    $p = $request->data();
    if (str_ends_with($request->url(), '/snapshot-proof')) {
        return Http::response($p + ['snapshot' => $proofMissing ? str_repeat('0', 64) : DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $p['backup'])->value('snapshot_id'), 'deleted' => false], 200, $headers);
    }
    if (str_ends_with($request->url(), '/transfer')) {
        check(DB::transactionLevel() === 0 && $lifecycleServer->fresh()->transfer !== null, 'native transfer notification occurs after native transfer row and archive detachment commit');
    }
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['test' => 'local']], 200, $headers);
    }
    if (str_ends_with($request->url(), '/server-key')) {
        if (isset($p['escrow_receipt'])) {
            $saved = RecoveryRepository::where('server_uuid', $lifecycleServer->uuid)->firstOrFail();
            $secret = json_decode(Crypt::decryptString($saved->secret), true);
            check(DB::transactionLevel() === 0 && $p['escrow_receipt'] === hash('sha256', base64_decode($secret['password_base64'])), 'old wrapper retirement receives committed matching escrow receipt');
            $finalized = true;
        }
        $prepared = true;

        return Http::response(['prepared' => true], 200, $headers);
    }
    if (str_ends_with($request->url(), '/recovery')) {
        $info = fixtureRecovery($p);
        $info['credential_mode'] = $prepared ? 'server' : 'legacy-profile';
        $info['key_finalized'] = $finalized;
        if ($prepared) {
            $info['password_base64'] = base64_encode('unique-disposable-server-password');
            $info['previous_password_base64'] = base64_encode('disposable-test-password');
        }

        return Http::response($info, 200, $headers);
    }
    if (($p['operation'] ?? null) === 'delete') {
        $deletes[] = $p['backup'];
    }

    return Http::response($p + ['status' => 'succeeded'], 200, $headers);
});
$repo = RecoveryVault::capture($backups->last());
check($prepared && ! $finalized && $repo->metadata['credential_mode'] === 'server', 'capture automatically prepares unique server key without premature old-wrapper retirement');
check(DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->count() === 2, 'active and previous server credentials are automatically versioned');
$versions = DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->get();
check(! str_contains(json_encode($versions), 'unique-disposable-server-password') && ! str_contains(json_encode($versions), base64_encode('disposable-test-password')), 'history contains encrypted credentials only');
DB::transaction(function () use ($backups) {
    rejected(fn () => RecoveryVault::finalize($backups->last()), 'uncommitted escrow cannot retire the original key', 'committed recovery');
});
RecoveryVault::finalize($backups->last());
check($finalized && $repo->fresh()->metadata['key_finalized'], 'successful committed escrow activates the unique credential');
RecoveryVault::capture($backups->last());
check(DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->count() === 2, 'repeated automatic capture does not duplicate key versions');
$legacyMetadata = $repo->fresh()->metadata;
$legacyMetadata['credential_mode'] = 'legacy-profile';
$repo->update(['metadata' => $legacyMetadata, 'secret' => Crypt::encryptString(json_encode(['password_base64' => base64_encode('disposable-test-password'), 'credentials_base64' => null]))]);
$repo = RecoveryVault::capture($backups->last());
check($repo->metadata['credential_mode'] === 'server' && DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->count() === 2, 'existing v3 shared-profile escrow upgrades only with matching authenticated previous credential history');
foreach ($backups as $i => $b) {
    RecoveryVault::snapshot($b, str_repeat((string) ($i + 1), 64), []);
}
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$historyPage = Livewire::test(RepositoryAdministration::class)->callTableAction('view-restic-key-history', $repo, data: ['password' => 'server-key-fixture'])->assertRedirect();
parse_str(parse_url($historyPage->effects['redirect'], PHP_URL_QUERY), $historyQuery);
$kit = RecoveryVault::kit($formAdmin, $repo->fresh(), $historyQuery['token']);
check(count($kit['key_history']) === 2 && $kit['secret']['password_base64'] === base64_encode('unique-disposable-server-password'), 'native password-confirmed history action reveals both generations and current server key');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$normal = RecoveryVault::issueReveal($formAdmin, $repo->fresh(), 'server-key-fixture');
parse_str(parse_url($normal, PHP_URL_QUERY), $normalQuery);
check(RecoveryVault::kit($formAdmin, $repo->fresh(), $normalQuery['token'])['key_history'] === [], 'current-key grant does not authorize historical-key disclosure');

// Mount the actual native page and confirmation, not a replacement plugin page.
$page = Livewire::test(EditServer::class, ['record' => $lifecycleServer->getRouteKey()])->mountAction('Delete')->assertStatus(200);
check(array_key_exists('restic_password', $page->instance()->mountedActions[0]['data']) && ! array_key_exists('restic_server_uuid', $page->instance()->mountedActions[0]['data']) && array_key_exists('restic_delete_mode', $page->instance()->mountedActions[0]['data']), 'actual mounted native Delete modal offers retention/destruction and password without a typed UUID');
$page->unmountAction();
fixturePreparedFinal($lifecycleServer, $backups->last(), $formAdmin);
DB::table('backups')->where('id', $backups->first()->id)->update(['is_locked' => true]);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$before = count(Http::recorded());
rejected(fn () => app(RetainedDeletion::class)->run($formAdmin, $lifecycleServer, 'server-key-fixture', $lifecycleServer->uuid), 'older locked snapshot blocks destructive lifecycle retention', 'Unlock older locked');
check(count(Http::recorded()) === $before, 'older lock refusal occurs before key fetch or snapshot deletion');
DB::table('backups')->where('id', $backups->first()->id)->update(['is_locked' => false]);
$proofMissing = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RetainedDeletion::class)->run($formAdmin, $lifecycleServer, 'server-key-fixture', $lifecycleServer->uuid), 'missing or wrong latest node snapshot blocks deletion before any older snapshot is forgotten', 'missing or unverified');
check($deletes === [] && $lifecycleServer->fresh() !== null, 'unverified latest snapshot leaves server and older backups intact');
$proofMissing = false;
DB::table('backups')->where('id', $backups->last()->id)->update(['is_locked' => true]);
$daemon = Mockery::mock(DaemonServerRepository::class);
$daemon->shouldReceive('setServer')->andReturnSelf();
$daemon->shouldReceive('delete')->once()->andReturnUsing(function () use (&$deletes, $backups) {
    check(count($deletes) === 2 && ! in_array($backups->last()->uuid, $deletes, true), 'older snapshots are forgotten before native daemon server deletion, latest locked snapshot remains');
});
app()->instance(DaemonServerRepository::class, $daemon);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(EditServer::class, ['record' => $lifecycleServer->getRouteKey()])->callAction('Delete', data: ['restic_retention_confirmed' => true, 'restic_server_uuid' => $lifecycleServer->uuid, 'restic_password' => 'server-key-fixture'])->assertRedirect();
check($lifecycleServer->fresh() === null && count($deletes) === 2, 'native Delete handler completes confirmed latest-only retention through the original native service');
check(DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->whereNull('forgotten_at')->count() === 1 && DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $backups->last()->uuid)->whereNull('forgotten_at')->exists(), 'deleted server retains exactly its latest successful snapshot mapping');
check(DB::table('ibyte_restic_operations')->where('server_uuid', $lifecycleServer->uuid)->where('operation', 'prune')->doesntExist(), 'lifecycle deletion never queues pruning against a server Wings has already removed');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$offlineUrl = RecoveryVault::issueReveal($formAdmin, $repo->fresh(), 'server-key-fixture', history: true);
parse_str(parse_url($offlineUrl, PHP_URL_QUERY), $offlineQuery);
$httpBefore = count(Http::recorded());
$offlineKit = RecoveryVault::kit($formAdmin, $repo->fresh(), $offlineQuery['token']);
check(count($offlineKit['key_history']) === 2 && $offlineKit['identity']['server_deleted_at'] !== null && count(Http::recorded()) === $httpBefore, 'deleted-server key history remains available without contacting its source node');
app()->forgetInstance(DaemonServerRepository::class);

// Execute the pinned native transfer service through the real native inline
// action and its success callback, with disposable authenticated HTTP mocks.
$lifecycleServer = Server::factory()->withNode()->create(['backup_limit' => 20, 'status' => null]);
$sourceNode = $lifecycleServer->node;
$lifecycleServer->allocation->update(['server_id' => $lifecycleServer->id]);
$lifecycleHost->nodes()->attach($sourceNode->id);
$destinationNode = Node::factory()->create(['scheme' => 'https', 'memory' => 10240, 'disk' => 102400]);
$allocation = Allocation::factory()->create(['node_id' => $destinationNode->id, 'server_id' => null]);
$lifecycleHost->nodes()->attach($destinationNode->id);
$transferBackups = collect();
for ($i = 0; $i < 2; $i++) {
    $b = Backup::factory()->create(['server_id' => $lifecycleServer->id, 'backup_host_id' => $lifecycleHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => now(), 'created_at' => now()->subMinutes(2 - $i)]);
    $transferBackups->push($b);
    $sourceRepo = RecoveryVault::capture($b);
    RecoveryVault::snapshot($b, str_repeat((string) ($i + 5), 64), []);
}
$deletes = [];
$reservedId = (string) Str::uuid();
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $reservedId, 'backup_uuid' => $transferBackups->last()->uuid, 'server_uuid' => $lifecycleServer->uuid, 'host_id' => $lifecycleHost->id, 'node_id' => $sourceNode->id, 'operation' => 'lifecycle', 'status' => 'reserved', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
rejected(fn () => app(InitiateBackupService::class)->setIgnoredFiles([])->handle($lifecycleServer, 'Blocked during retention'), 'durable lifecycle reservation blocks concurrent native backup creation', 'reserved');
rejected(fn () => app(Operations::class)->queue($transferBackups->last(), 'stats', (string) Str::uuid()), 'durable lifecycle reservation blocks competing maintenance dispatch', 'reserved');
$transferAction = TestAction::make('exclude_transfer')->schemaComponent(true, 'form');
fixturePreparedFinal($lifecycleServer, $transferBackups->last(), $formAdmin, 'transfer', $destinationNode->id);
$transferPage = Livewire::test(EditServer::class, ['record' => $lifecycleServer->getRouteKey()])->mountAction($transferAction)->assertStatus(200);
check(str_contains($transferPage->instance()->getMountedAction()->getModalDescription(), 'Restic backups will NOT be transferred') && array_key_exists('restic_password', $transferPage->instance()->mountedActions[0]['data']) && array_key_exists('node_id', $transferPage->instance()->mountedActions[0]['data']), 'native Transfer confirmation preserves native node form and adds latest-only warning and password');
$transferPage->unmountAction();
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(EditServer::class, ['record' => $lifecycleServer->getRouteKey()])->callAction($transferAction, data: ['node_id' => $destinationNode->id, 'allocation_id' => $allocation->id, 'allocation_additional' => [], 'backups' => [], 'restic_retention_confirmed' => true, 'restic_server_uuid' => $lifecycleServer->uuid, 'restic_password' => 'server-key-fixture'])->assertHasNoActionErrors()->assertStatus(200);
$transfer = $lifecycleServer->fresh()->transfer;
check($transfer && $transfer->new_node === $destinationNode->id && $lifecycleServer->fresh()->node_id === $sourceNode->id, 'original native Transfer service starts transfer without prematurely moving server');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $reservedId)->value('status') === 'dismissed', 'password-confirmed retry safely supersedes interrupted lifecycle reservation');
check($deletes === [$transferBackups->first()->uuid] && $lifecycleServer->backups()->count() === 0, 'transfer forgets older source snapshot and archives latest outside destination native backup rows');
check(DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $sourceRepo->id)->whereNull('forgotten_at')->count() === 1 && $sourceRepo->fresh()->metadata['destination_node_id'] === $destinationNode->id, 'transfer keeps exactly latest source mapping and records source/destination recovery identity');
Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transfer') && $request['backups'] === []);
$oldNodeDeletion = Mockery::mock(DaemonServerRepository::class);
$oldNodeDeletion->shouldReceive('setServer')->andReturnSelf();
$oldNodeDeletion->shouldReceive('setNode')->once()->with(Mockery::on(fn ($node) => $node->id === $sourceNode->id))->andReturnSelf();
$oldNodeDeletion->shouldReceive('delete')->once()->andReturnNull();
app()->instance(DaemonServerRepository::class, $oldNodeDeletion);
$callback = Request::create('/disposable-transfer/success', 'POST');
$callback->attributes->set('node', $sourceNode);
rejected(fn () => app(ServerTransferController::class)->success($callback, $lifecycleServer->fresh()), 'source node cannot forge native transfer completion');
$callback->attributes->set('node', $destinationNode);
check(app(ServerTransferController::class)->success($callback, $lifecycleServer->fresh())->status() === 204, 'authenticated destination native transfer callback completes with archived Restic source backup');
$lifecycleServer = $lifecycleServer->fresh();
check($lifecycleServer->node_id === $destinationNode->id && $sourceRepo->fresh()->node_id === $sourceNode->id, 'server moves to destination while retained repository stays on original node');
$destinationBackup = Backup::factory()->create(['server_id' => $lifecycleServer->id, 'backup_host_id' => $lifecycleHost->id, 'completed_at' => null, 'is_successful' => false, 'is_locked' => false]);
$destinationRepo = RecoveryVault::capture($destinationBackup);
check($destinationRepo->id !== $sourceRepo->id && $destinationRepo->node_id === $destinationNode->id, 'post-transfer backup escrows a separate destination repository instead of rebinding old snapshots');
$oldOwner = $sourceRepo->fresh()->owner_id;
$lifecycleServer->update(['owner_id' => User::factory()->create()->id]);
check($sourceRepo->fresh()->owner_id === $oldOwner && $destinationRepo->fresh()->owner_id === $lifecycleServer->owner_id, 'source recovery inventory preserves former owner after destination ownership changes');
app()->forgetInstance(DaemonServerRepository::class);
