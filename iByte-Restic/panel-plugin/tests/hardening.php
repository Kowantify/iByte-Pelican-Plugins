<?php

use App\Models\Backup;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Jobs\PollRepositoryMaintenance;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RetainedMaintenance;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\FailureAlerts;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

auth()->login($formAdmin);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
$alertServer = (string) Str::uuid();
$alertRequest = (string) Str::uuid();
$alertAdmin = User::factory()->create();
$alertAdmin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
$rootCount = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROOT_ADMIN))->count();
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $alertRequest, 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $alertServer,
    'node_id' => 1, 'host_id' => $uiHost->id, 'operation' => 'create', 'status' => 'failed', 'error' => '<script>disposable-secret-password</script>', 'payload' => '{"password":"disposable-secret-password"}', 'created_at' => now(), 'updated_at' => now()]);
FailureAlerts::operation($alertRequest);
FailureAlerts::operation($alertRequest);
$alertQuery = DB::table('notifications')->where('data', 'like', '%'.$alertServer.'%');
check((clone $alertQuery)->count() === $rootCount, 'failed operation produces one persistent notification per root administrator despite replay');
$alertData = json_decode((clone $alertQuery)->first()->data, true);
check($alertData['format'] === 'filament' && ! str_contains(json_encode($alertData), 'disposable-secret-password') && ! str_contains(json_encode($alertData), '<script>'), 'failure alerts use native Filament format and never copy node payload/error secrets');
(clone $alertQuery)->update(['read_at' => now()]);
FailureAlerts::dispatch();
check((clone $alertQuery)->whereNull('read_at')->count() === 0, 'scheduler catch-up preserves acknowledgement instead of resurrecting read alerts');
check(DB::table('notifications')->where('notifiable_id', $generationServer->owner_id)->where('data', 'like', '%'.$alertServer.'%')->doesntExist(), 'server owners receive no cross-server root maintenance alerts');
Livewire::test(RepositoryAdministration::class)->mountAction('operation-alerts')->assertSet('mountedActions.0.name', 'operation-alerts');

$maintNode = Node::factory()->create(['scheme' => 'https']);
$maintRepo = $legacyRepo->replicate();
$maintRepo->fill(['node_id' => $maintNode->id, 'server_uuid' => (string) Str::uuid(), 'metadata' => ['host_name' => 'Retained fixture', 'archived_at' => now()->toIso8601String(), 'repository' => '/var/lib/ibyte-restic/repositories/host-'.$legacyRepo->host_id.'/node-'.$maintNode->id.'/retained-fixture']])->save();
check(! Server::where('uuid', $maintRepo->server_uuid)->exists(), 'retained maintenance fixture has no live server');
rejected(fn () => RetainedMaintenance::queue($generationServer->user, $maintRepo, 'prune'), 'ordinary owner cannot queue retained maintenance');
rejected(fn () => RetainedMaintenance::queue($formAdmin, $maintRepo, 'delete'), 'maintenance cannot select or forget snapshots');
$maintRequest = RetainedMaintenance::queue($formAdmin, $maintRepo, 'stats');
$maintRow = DB::table('ibyte_restic_operations')->where('request_uuid', $maintRequest)->first();
$maintPayload = json_decode($maintRow->payload, true);
check($maintRow->status === 'queued' && ! Backup::where('uuid', $maintRow->backup_uuid)->exists() && $maintPayload['repository_fingerprint'] === hash('sha256', $maintRepo->metadata['repository']), 'retained maintenance persists immutable source/generation/location context without inventing a native backup');
rejected(fn () => RetainedMaintenance::queue($formAdmin, $maintRepo, 'prune'), 'pending retained work is coalesced rather than submitted twice');
$maintMetrics = ['total_size' => 30, 'total_uncompressed_size' => 60, 'logical_bytes' => 120, 'snapshots_count' => 2, 'compression_ratio' => 2, 'compression_space_saving' => 50, 'repository_file_bytes' => 40, 'snapshots' => []];
$maintReply = 'running';
$maintForged = false;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($maintNode, $maintMetrics, &$maintReply, &$maintForged) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$maintNode->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['version' => '3.0.0-preview.2', 'ready' => true, 'protocol' => 1, 'retained_maintenance' => 'v1', 'profiles' => ['default' => 'local']], 200, $headers);
    }
    $state = $request->data() + ['status' => $maintReply, 'repository' => $maintMetrics];
    if ($maintForged) {
        $state['generation']++;
    }

    return Http::response($state, 200, $headers);
});
(new PollRepositoryMaintenance($maintRequest))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $maintRequest)->value('status') === 'running', 'retained worker records pending execution without claiming success');
$maintReply = 'succeeded';
(new PollRepositoryMaintenance($maintRequest))->handle(app(Transport::class));
check(json_decode(StorageReport::repository($maintRepo)->metrics, true)['repository'] === $maintMetrics, 'retained completion persists measured storage after original server deletion');
$maintReplayCount = count(Http::recorded());
(new PollRepositoryMaintenance($maintRequest))->handle(app(Transport::class));
check(count(Http::recorded()) === $maintReplayCount, 'successful retained job replay performs no node requests');
$forgedRequest = RetainedMaintenance::queue($formAdmin, $maintRepo, 'check');
$maintForged = true;
(new PollRepositoryMaintenance($forgedRequest))->handle(app(Transport::class));
check(DB::table('ibyte_restic_operations')->where('request_uuid', $forgedRequest)->value('status') === 'unknown', 'wrong-generation retained reply is quarantined rather than accepted');
rejected(fn () => RetainedMaintenance::queue($formAdmin, $maintRepo, 'prune'), 'unverified retained work blocks subsequent maintenance');
$maintenanceAction = Livewire::test(RepositoryAdministration::class)->instance()->getTable()->getFlatRecordActions()['maintain-repository']->record($maintRepo);
check(str_contains(strip_tags($maintenanceAction->toHtml()), 'Repository maintenance'), 'retained maintenance is a readable labelled Administrative menu item');
DB::table('ibyte_restic_operations')->where('request_uuid', $forgedRequest)->update(['status' => 'dismissed']);
rejected(fn () => RetainedMaintenance::queue($formAdmin, $maintRepo, 'stats'), 'same retained operation is rate limited after terminal completion');
$maintRepo->update(['metadata' => array_merge($maintRepo->metadata, ['automatic_maintenance' => true])]);
$autoRetainedBefore = DB::table('ibyte_restic_operations')->where('server_uuid', $maintRepo->server_uuid)->count();
$autoRetainedSnapshots = DB::table('ibyte_restic_recovery_snapshots')->count();
RetainedMaintenance::automatic();
RetainedMaintenance::automatic();
check(DB::table('ibyte_restic_operations')->where('server_uuid', $maintRepo->server_uuid)->count() === $autoRetainedBefore + 1 && DB::table('ibyte_restic_operations')->where('server_uuid', $maintRepo->server_uuid)->where('operation', 'prune')->where('status', 'queued')->exists(), 'opted-in retained repository queues only its due maintenance once through native scheduler service');
check(DB::table('ibyte_restic_recovery_snapshots')->count() === $autoRetainedSnapshots, 'automatic retained maintenance never expires snapshots or deletes repository records');
