<?php

use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Admin\RepositoryStatistics;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Recovery\RepositoryRemoval;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

$formAdmin->update(['password' => Hash::make('repository-fixture')]);
auth()->login($formAdmin);
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));
$removeServer = Server::factory()->withNode()->create(['backup_limit' => 20]);
$removeHost = BackupHost::create(['name' => 'Explicit repository cleanup', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
$removeHost->nodes()->attach($removeServer->node_id);
$removeBackups = collect();
$nodeSnapshots = [];
for ($i = 0; $i < 2; $i++) {
    $b = Backup::factory()->create(['server_id' => $removeServer->id, 'backup_host_id' => $removeHost->id, 'is_successful' => true, 'completed_at' => now(), 'is_locked' => false]);
    $removeBackups->push($b);
    $nodeSnapshots[$b->uuid] = str_repeat((string) ($i + 3), 64);
}
$capable = true;
$unknownSnapshot = false;
$ambiguous = false;
$nodeRemoved = false;
$deleteRequests = [];
$locationChanged = false;
$snapshotDeleteRequests = 0;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($removeServer, &$nodeSnapshots, &$capable, &$unknownSnapshot, &$ambiguous, &$nodeRemoved, &$deleteRequests, &$locationChanged, &$snapshotDeleteRequests) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$removeServer->node->daemon_token_id.')'];
    $p = $request->data();
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local'], 'repository_delete' => $capable ? 'empty-local-v1' : null], 200, $headers);
    }
    if (str_ends_with($request->url(), '/recovery')) {
        return Http::response(fixtureRecovery($p), 200, $headers);
    }
    if (str_ends_with($request->url(), '/repository-plan')) {
        $snapshots = $nodeSnapshots;
        if ($unknownSnapshot) {
            $snapshots[(string) Str::uuid()] = str_repeat('f', 64);
        }

        return Http::response($p + ['repository' => fixtureRecovery($p)['repository'].($locationChanged ? '/changed-location' : ''), 'snapshots' => $snapshots, 'deleted' => $nodeRemoved], 200, $headers);
    }
    if (($p['operation'] ?? null) === 'delete') {
        $snapshotDeleteRequests++;
        unset($nodeSnapshots[$p['backup']]);
    }
    if (($p['operation'] ?? null) === 'repo-delete') {
        check($nodeSnapshots === [], 'physical repository cleanup is requested only after recognized snapshots are forgotten');
        check(DB::table('ibyte_restic_operations')->where('request_uuid', $p['request_id'])->exists(), 'explicit repository deletion intent survives outside the snapshot-deletion transaction');
        $nodeRemoved = true;
        $deleteRequests[] = $p['request_id'];
        if ($ambiguous) {
            $ambiguous = false;

            return Http::failedConnection();
        }
    }

    return Http::response($p + ['status' => 'succeeded'], 200, $headers);
});
$removeRepo = RecoveryVault::capture($removeBackups->first());
foreach ($removeBackups as $b) {
    RecoveryVault::snapshot($b, $nodeSnapshots[$b->uuid], []);
}
$page = Livewire::test(RepositoryAdministration::class)->assertStatus(200);
$table = $page->instance()->getTable();
check($table->getRecordActionsPosition() === RecordActionsPosition::BeforeColumns && count($table->getVisibleColumns()) <= 5, 'repository controls precede a compact default table instead of overflowing behind path/history columns');
$actions = $table->getRecordActions();
check($actions[0]->getName() === 'view-restic-keys' && $actions[1]->getName() === 'view-restic-key-history' && isset($table->getFlatRecordActions()['delete-repository']), 'key viewing/history are direct leading controls and repository deletion is in the labelled More menu');
check($table->getColumn('metadata.repository')->isToggledHidden(), 'long repository paths remain available as optional detail columns');
$page->assertTableActionVisible('view-restic-keys', $removeRepo)->assertTableActionVisible('delete-repository', $removeRepo);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$beforeCalls = count(Http::recorded());
rejected(fn () => app(RepositoryRemoval::class)->run($removeServer->user, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'customers cannot request repository deletion');
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'wrong-password', $removeServer->uuid, true), 'repository deletion requires the current administrator password');
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', 'wrong-uuid', true), 'repository deletion requires full exact server UUID');
check(count(Http::recorded()) === $beforeCalls, 'invalid authorization/confirmation does not contact Wings');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$capable = false;
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'old node capability refuses cleanup before forgetting any snapshot', 'Update the matching Wings');
check(count($nodeSnapshots) === 2, 'capability mismatch preserves all node snapshots');
$capable = true;
DB::table('backups')->where('id', $removeBackups->first()->id)->update(['is_locked' => true]);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'locked snapshots protect the entire repository from cleanup', 'Unlock protected');
DB::table('backups')->where('id', $removeBackups->first()->id)->update(['is_locked' => false]);
$unknownSnapshot = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'unknown snapshots refuse cleanup before native forget', 'unrecognized snapshots');
check(count($nodeSnapshots) === 2 && ! isset($removeRepo->fresh()->metadata['delete_request_uuid']), 'unrecognized repository contents preserve backups and do not reserve destructive work');
$unknownSnapshot = false;
$ambiguous = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'lost repository-delete response never claims confirmed deletion');
$request = $removeRepo->fresh()->metadata['delete_request_uuid'];
check($nodeRemoved && $removeServer->backups()->count() === 2 && DB::table('ibyte_restic_operations')->where('request_uuid', $request)->value('status') === 'unknown', 'ambiguous completion retains native metadata and durable request for reconciliation');
rejected(fn () => app(Operations::class)->queue($removeBackups->first(), 'stats', (string) Str::uuid()), 'uncertain repository deletion blocks competing maintenance/create dispatch', 'reserved');
rejected(fn () => RecoveryVault::capture($removeBackups->first()), 'automatic capture cannot overwrite a committed deletion intent', 'deletion is reserved');
check($removeRepo->fresh()->metadata['delete_request_uuid'] === $request, 'deletion identity survives background synchronization attempts');
$locationChanged = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true), 'retry refuses a changed repository location before additional native forget calls', 'location or plan changed');
check($snapshotDeleteRequests === 2 && $deleteRequests === [$request], 'location drift cannot redirect a pending destructive request to another repository');
$locationChanged = false;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
app(RepositoryRemoval::class)->run($formAdmin, $removeRepo, 'repository-fixture', $removeServer->uuid, true);
check($deleteRequests === [$request, $request] && $removeServer->backups()->count() === 0 && isset($removeRepo->fresh()->metadata['repository_deleted_at']), 'native root action retries the same request and records only confirmed repository deletion');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $request)->value('status') === 'succeeded' && DB::table('ibyte_restic_operations')->where('server_uuid', $removeServer->uuid)->where('operation', 'prune')->doesntExist(), 'confirmed cleanup retains audit and does not queue prune against a removed repository');
check($removeRepo->fresh()->getRawOriginal('secret') !== null && DB::table('ibyte_restic_key_history')->where('repository_id', $removeRepo->id)->exists(), 'repository removal retains encrypted credentials and key history');
$removedPage = Livewire::test(RepositoryStatistics::class);
check($removedPage->instance()->getTable()->getColumn('storage')->record($removeRepo->fresh())->getState() === 'Removed', 'removed repository does not display stale pre-deletion stored bytes');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$beforeArchived = count(Http::recorded());
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $sourceRepo->fresh(), 'repository-fixture', $sourceRepo->server_uuid, true), 'retained source repository cannot bypass live-server authentication for cleanup', 'deleted/transferred');
check(count(Http::recorded()) === $beforeArchived, 'archived cleanup refusal never contacts a different destination node');
check((app(Transport::class)->context($removeBackups->first(), false)['generation'] ?? 0) === 0, 'old backup context stays on its original generation after repository deletion');
$remote = $removeRepo->fresh();
$remote->backend = 's3';
$remote->save();
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RepositoryRemoval::class)->run($formAdmin, $remote, 'repository-fixture', $removeServer->uuid, true), 'S3 cleanup is explicitly unsupported instead of a false successful delete', 'S3 repository deletion is not supported');
$remote->update(['backend' => 'local']);
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($removeServer);
$backupsPage = Livewire::test(ListBackups::class)->assertActionDoesNotExist('manage-restic-backups')->assertActionDoesNotExist('ibyte-restic-remove-record')->assertTableActionDoesNotExist('ibyte-restic-remove-record');
check(true, 'even root admins use the admin area for forced cleanup; customer resource contains no cleanup actions');
auth()->login($removeServer->user);
Livewire::test(ListBackups::class)->assertActionDoesNotExist('manage-restic-backups')->assertActionDoesNotExist('ibyte-restic-remove-record');
check(true, 'customer native Backups exposes neither admin keys nor repository/record cleanup');
auth()->login($formAdmin);
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));

// An existing queued create from before the credential update must upgrade its
// escrow rather than retrying finalize forever. Node transport is mocked here.
$legacyQueuedServer = Server::factory()->withNode()->create(['backup_limit' => 5]);
$legacyQueuedHost = BackupHost::create(['name' => 'Old queued credential fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
$legacyQueuedHost->nodes()->attach($legacyQueuedServer->node_id);
$legacyQueuedBackup = Backup::factory()->create(['server_id' => $legacyQueuedServer->id, 'backup_host_id' => $legacyQueuedHost->id, 'completed_at' => null, 'is_successful' => false, 'is_locked' => false]);
Http::swap(new Factory);
Http::preventStrayRequests();
$queuedCaptures = 0;
Http::fake(function ($request) use ($legacyQueuedServer, &$queuedCaptures) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$legacyQueuedServer->node->daemon_token_id.')'];
    $p = $request->data();
    if (str_ends_with($request->url(), '/recovery')) {
        $queuedCaptures++;

        return Http::response(fixtureRecovery($p), 200, $headers);
    }

    return Http::response($p + ['status' => 'succeeded', 'snapshot' => str_repeat('a', 64), 'bytes' => 10], 200, $headers);
});
$legacyRepo = RecoveryVault::capture($legacyQueuedBackup);
$legacyMetadata = $legacyRepo->metadata;
unset($legacyMetadata['credential_mode'], $legacyMetadata['key_finalized']);
$legacyRepo->update(['metadata' => $legacyMetadata]);
$queuedCaptures = 0;
$payload = app(Transport::class)->context($legacyQueuedBackup, false) + ['operation' => 'create', 'request_id' => $legacyQueuedBackup->uuid, 'ignore' => []];
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $legacyQueuedBackup->uuid, 'backup_uuid' => $legacyQueuedBackup->uuid, 'server_uuid' => $legacyQueuedServer->uuid, 'host_id' => $legacyQueuedHost->id, 'node_id' => $legacyQueuedServer->node_id, 'operation' => 'create', 'status' => 'queued', 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
(new PollOperation($legacyQueuedBackup->uuid))->handle(app(Transport::class));
check($queuedCaptures === 1 && $legacyQueuedBackup->fresh()->is_successful && $legacyRepo->fresh()->metadata['credential_mode'] === 'server', 'pre-update queued backup automatically captures current server credentials before finalization and completes');
