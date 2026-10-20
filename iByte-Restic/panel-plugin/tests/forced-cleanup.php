<?php

use App\Enums\CustomizationKey;
use App\Enums\ServerState;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Backups\RecordRemoval;
use IByte\Restic\Recovery\ForcedRepositoryRemoval;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

auth()->login($formAdmin);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
foreach ([true, false] as $icons) {
    $formAdmin->setCustomization(CustomizationKey::ButtonStyle, $icons);
    $page = Livewire::test(RepositoryAdministration::class);
    $table = $page->instance()->getTable();
    foreach (['view-restic-keys', 'view-restic-key-history', 'remove-repository-records'] as $name) {
        $action = $table->getFlatRecordActions()[$name]->record($legacyRepo);
        check($action->getView() === Action::BUTTON_VIEW && str_contains(strip_tags($action->toHtml()), $action->getLabel()), 'actual leading control renders readable text with button_style='.json_encode($icons).': '.$name);
    }
    foreach (['download-recovery-kit', 'delete-repository'] as $name) {
        $action = $table->getFlatRecordActions()[$name]->record($legacyRepo);
        check($action->getView() === Action::GROUPED_VIEW && str_contains(strip_tags($action->toHtml()), $action->getLabel()), 'actual dropdown item renders readable text with button_style='.json_encode($icons).': '.$name);
    }
    $page->assertSee('Key history')->assertSee('Remove Panel records')->assertSee('Delete entire repository');
}
$formAdmin->setCustomization(CustomizationKey::ButtonStyle, true);

// No node link, displaced execution binding, locked/recent rows and a lifecycle
// reservation cannot prevent explicit root metadata cleanup. Restore stays fenced.
$forceServer = Server::factory()->withNode()->create(['backup_limit' => 20]);
$forceRepo = $legacyRepo->replicate();
$forceRepo->fill(['server_uuid' => $forceServer->uuid, 'node_id' => $forceServer->node_id,
    'server_name' => $forceServer->name, 'metadata' => ['repository' => '/var/lib/ibyte-restic/repositories/host-'.$legacyRepo->host_id.'/node-'.$forceServer->node_id.'/'.$forceServer->uuid, 'host_name' => 'Force fixture']])->save();
$secretBefore = $forceRepo->getRawOriginal('secret');
$forceBackup = Backup::factory()->create(['server_id' => $forceServer->id, 'backup_host_id' => $forceRepo->host_id, 'is_locked' => true, 'completed_at' => null, 'is_successful' => false]);
DB::table('servers')->where('id', $forceServer->id)->update(['status' => ServerState::RestoringBackup->value]);
$restoreRequest = (string) Str::uuid();
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $restoreRequest, 'backup_uuid' => $forceBackup->uuid, 'server_uuid' => $forceServer->uuid, 'node_id' => $forceServer->node_id, 'host_id' => $forceRepo->host_id, 'operation' => 'restore', 'status' => 'unknown', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::failedConnection());
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(RepositoryAdministration::class)->callTableAction('remove-repository-records', $forceRepo, data: ['password' => 'wrong'])->assertHasTableActionErrors(['password']);
check($forceBackup->fresh() !== null, 'wrong admin password preserves locked pending metadata');
Livewire::test(RepositoryAdministration::class)->callTableAction('remove-repository-records', $forceRepo, data: ['password' => 'repository-fixture'])->assertHasNoTableActionErrors();
check($forceBackup->fresh() === null && Http::recorded()->isEmpty(), 'admin password alone removes locked recent stuck records without node connectivity or mapping');
check($forceServer->fresh()->status === ServerState::RestoringBackup && DB::table('ibyte_restic_operations')->where('request_uuid', $restoreRequest)->value('status') === 'unknown', 'forced metadata cleanup retains native restore protection and unresolved restore evidence');
check($forceRepo->fresh()->getRawOriginal('secret') === $secretBefore, 'forced metadata cleanup preserves escrow even with a restore unresolved');
rejected(fn () => RecordRemoval::repository($forceServer->user, $forceRepo, 'repository-fixture'), 'server owner cannot use repository metadata override');
$single = Backup::factory()->create(['server_id' => $forceServer->id, 'backup_host_id' => $forceRepo->host_id, 'is_locked' => true, 'completed_at' => null]);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-remove-record', data: ['backup_uuid' => $single->uuid, 'password' => 'wrong'])->assertHasActionErrors(['password']);
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-remove-record', data: ['backup_uuid' => $single->uuid, 'password' => 'repository-fixture'])->assertHasNoActionErrors();
check($single->fresh() === null && Http::recorded()->isEmpty(), 'global admin cleanup removes a locked recent record using only selection and password even during unresolved restore');

// Fresh force fixture also represents a retained/deleted-server inventory row.
DB::table('servers')->where('id', $forceServer->id)->update(['status' => null]);
$forceBackup = Backup::factory()->create(['server_id' => $forceServer->id, 'backup_host_id' => $forceRepo->host_id, 'is_locked' => true, 'completed_at' => null]);
$metadata = $forceRepo->metadata;
$metadata['archived_at'] = now()->toIso8601String();
$forceRepo->update(['metadata' => $metadata, 'server_deleted_at' => now()]);
$capable = false;
$offline = false;
$forgedReply = false;
$forceRequests = [];
$physicallyRemoved = false;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($forceServer, &$capable, &$offline, &$forgedReply, &$forceRequests, &$physicallyRemoved) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$forceServer->node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local'], 'force_repository_delete' => $capable ? 'local-v1' : null], 200, $headers);
    }
    $p = $request->data();
    check(str_ends_with($request->url(), '/api/restic/repository-delete') && $p['operation'] === 'repo-force-delete', 'force cleanup uses the daemon-authenticated retained-repository endpoint, never normal snapshot deletion');
    check(DB::table('ibyte_restic_operations')->where('request_uuid', $p['request_id'])->exists(), 'force intent commits before physical deletion request');
    $forceRequests[] = $p;
    $physicallyRemoved = true;
    if ($offline) {
        return Http::failedConnection();
    }
    if ($forgedReply) {
        $p['node']++;
    }

    return Http::response($p + ['status' => 'succeeded'], 200, $headers);
});
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($formAdmin, $forceRepo, 'wrong'), 'physical force deletion requires current root password');
check(Http::recorded()->isEmpty(), 'wrong password never contacts Wings');
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($forceServer->user, $forceRepo, 'repository-fixture'), 'customers cannot call physical force deletion directly');
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($formAdmin, $forceRepo, 'repository-fixture'), 'old Wings capability cannot receive a destructive force request', 'capability');
check($forceRequests === [], 'old Wings leaves physical repository and Panel metadata intact');
$capable = true;
$offline = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($formAdmin, $forceRepo, 'repository-fixture'), 'lost force response remains unconfirmed');
check($forceBackup->fresh() !== null && ! isset($forceRepo->fresh()->metadata['repository_deleted_at']), 'HTTP ambiguity retains backup records and recovery information');
$offline = false;
$forgedReply = true;
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($formAdmin, $forceRepo, 'repository-fixture'), 'wrong node response cannot finalize physical deletion', 'identity');
check($forceBackup->fresh() !== null, 'forged response leaves metadata intact');
$forgedReply = false;
Livewire::test(RepositoryAdministration::class)->callTableAction('delete-repository', $forceRepo, data: ['password' => 'repository-fixture'])->assertHasNoTableActionErrors();
check($physicallyRemoved && $forceBackup->fresh() === null && isset($forceRepo->fresh()->metadata['repository_deleted_at']), 'root UI password-only force removes entire retained repository and locked pending Panel rows after verified node success');
check(count(array_unique(array_column($forceRequests, 'request_id'))) === 1, 'lost and forged response retries keep one committed destructive request UUID');
check($forceRepo->fresh()->getRawOriginal('secret') === $secretBefore && DB::table('ibyte_restic_operations')->where('server_uuid', $forceServer->uuid)->where('operation', 'prune')->doesntExist(), 'physical force deletion retains keys/history and never schedules prune');
$absentRepo = $forceRepo->replicate();
$absentUuid = (string) Str::uuid();
$absentRepo->fill(['server_uuid' => $absentUuid, 'metadata' => ['repository' => '/var/lib/ibyte-restic/repositories/host-'.$forceRepo->host_id.'/node-'.$forceRepo->node_id.'/'.$absentUuid], 'backend' => 's3'])->save();
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$beforeS3 = count(Http::recorded());
rejected(fn () => app(ForcedRepositoryRemoval::class)->run($formAdmin, $absentRepo, 'repository-fixture'), 'S3 force deletion is explicitly refused instead of claiming unsupported physical removal', 'S3');
check(count(Http::recorded()) === $beforeS3, 'unsupported S3 force deletion makes no node request');
$absentRepo->update(['backend' => 'local']);
check(! Server::where('uuid', $absentUuid)->exists(), 'retained cleanup fixture has no live Panel server row');
app(ForcedRepositoryRemoval::class)->run($formAdmin, $absentRepo, 'repository-fixture');
check(isset($absentRepo->fresh()->metadata['repository_deleted_at']), 'physical force cleanup works from retained inventory with no live Panel server lookup');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
Livewire::test(RepositoryAdministration::class)->callTableAction('view-restic-key-history', $forceRepo, data: ['password' => 'wrong'])->assertHasTableActionErrors(['password']);
check(true, 'key-history password errors bind to the actual modal field');
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($forceServer);
Livewire::test(ListBackups::class)->assertActionDoesNotExist('ibyte-restic-remove-record')->assertTableActionDoesNotExist('remove-repository-records')->assertTableActionDoesNotExist('delete-repository');
check(true, 'force cleanup is absent from the client area even for root administrators');
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));
