<?php

// Real pinned Filament/Livewire components, disposable SQLite and mocked HTTP.
use App\Enums\CustomizationKey;
use App\Filament\Admin\Resources\BackupHosts\Pages\EditBackupHost;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use IByte\Restic\Admin\ListRecoveryRepositories;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Admin\RepositoryStatistics;
use IByte\Restic\ResticSchema;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

auth()->login($formAdmin);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::failedConnection());
$uiPages = [ListRecoveryRepositories::class, RepositoryStatistics::class, RepositoryAdministration::class];
$uiRestricted = ['view-restic-keys', 'view-restic-key-history', 'download-recovery-kit', 'remove-repository-records', 'delete-repository'];
check(count(RecoveryResource::getNavigationItems()) === 1 && RecoveryResource::getNavigationItems()[0]->getLabel() === 'Restic Backups', 'resource contributes exactly one Restic Backups sidebar entry');
check(array_values(array_filter(Filament::getPanel('admin')->getResources(), fn ($class) => str_starts_with($class, 'IByte\\Restic\\'))) === [RecoveryResource::class], 'the three tab pages are not separate sidebar resources');

foreach ([true, false] as $uiIcons) {
    $formAdmin->setCustomization(CustomizationKey::ButtonStyle, $uiIcons);
    foreach ($uiPages as $uiPage) {
        $uiView = Livewire::test($uiPage)->assertStatus(200)->assertSee('Restic Backups');
        $uiNav = $uiView->instance()->getSubNavigation();
        check($uiPage::getSubNavigationPosition() === SubNavigationPosition::Top
            && array_map(fn ($item) => $item->getLabel(), $uiNav) === ['Servers', 'Stats', 'Administrative'], 'native top tabs have the same order on '.$uiPage.' with button_style='.json_encode($uiIcons));
        foreach ($uiNav as $uiItem) {
            $uiView->assertSee($uiItem->getUrl());
        }
        check(str_contains($uiView->html(), 'fi-page-sub-navigation-tabs'), 'native tab markup renders on '.$uiPage);
        if ($uiPage !== RepositoryAdministration::class) {
            foreach ($uiRestricted as $uiAction) {
                $uiView->assertTableActionDoesNotExist($uiAction, record: $legacyRepo);
            }
            $uiView->assertActionDoesNotExist('ibyte-restic-remove-record')->assertActionDoesNotExist('host-settings')->assertActionDoesNotExist('host-maintenance');
            // Direct mounting cannot discover a destructive action on a safe tab.
            $uiView->mountTableAction('delete-repository', $legacyRepo)->assertTableActionNotMounted('delete-repository');
            check(true, 'key/destructive handlers cannot be mounted from '.$uiPage);
        }
        check(! str_contains($uiView->html(), 'password_base64') && ! str_contains($uiView->html(), 'credentials_base64'), 'no credentials enter initial tab HTML or Livewire state: '.$uiPage);
    }
}
$formAdmin->setCustomization(CustomizationKey::ButtonStyle, true);
check(Http::recorded()->isEmpty(), 'all tab renders and rejected mounts perform zero node requests');

$uiHost = BackupHost::create(['name' => 'UI settings fixture', 'schema' => 'restic', 'configuration' => ResticSchema::DEFAULT_CONFIGURATION]);
$uiPlainHost = BackupHost::create(['name' => 'UI ordinary host', 'schema' => 'wings', 'configuration' => []]);
$uiSettings = Livewire::test(RepositoryAdministration::class)->mountAction(TestAction::make('host-settings')->schemaComponent(true, 'content'))->assertActionMounted(TestAction::make('host-settings')->schemaComponent(true, 'content'));
$uiSettings->set('mountedActions.0.data.host_id', $uiHost->id);
check($uiSettings->get('mountedActions.0.data.staging_enabled') === false && $uiSettings->get('mountedActions.0.data.restore_fixture_enabled') === false, 'central host selector loads both saved false defaults');
$uiSettings->setActionData(['host_id' => $uiHost->id, 'staging_enabled' => true, 'restore_fixture_enabled' => false])->callMountedAction()->assertHasNoActionErrors();
check($uiHost->fresh()->configuration === array_merge(ResticSchema::DEFAULT_CONFIGURATION, ['staging_enabled' => true]), 'Administrative saves only selected host safety flags and preserves backend/profile');
$uiNativeEdit = Livewire::test(EditBackupHost::class, ['record' => $uiHost->id])->call('save')->assertHasNoFormErrors();
check($uiHost->fresh()->configuration['staging_enabled'] === true && ! str_contains($uiNativeEdit->html(), 'Enable preview on disposable staging servers'), 'native basic edit preserves enabled safety settings without showing advanced toggles');
$uiNativeEdit->assertActionDoesNotExist('ibyte-restic-storage')->assertActionDoesNotExist('ibyte-restic-check')->assertActionDoesNotExist('ibyte-restic-prune')->assertActionExists('ibyte-restic-overview');
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-settings')->schemaComponent(true, 'content'), data: ['host_id' => $uiPlainHost->id, 'staging_enabled' => true, 'restore_fixture_enabled' => true])->assertHasActionErrors(['host_id']);
check($uiPlainHost->fresh()->configuration === [], 'forged ordinary host selection cannot change Wings settings');
$uiBackup = Backup::factory()->create(['server_id' => $generationServer->id, 'backup_host_id' => $uiHost->id]);
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-settings')->schemaComponent(true, 'content'), data: ['host_id' => $uiHost->id, 'staging_enabled' => false, 'restore_fixture_enabled' => true])->assertHasActionErrors(['host_id']);
check($uiHost->fresh()->configuration['staging_enabled'] === true && ! $uiHost->fresh()->configuration['restore_fixture_enabled'], 'central settings cannot weaken host immutability while backups exist');
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-maintenance')->schemaComponent(true, 'content'), data: ['host_id' => $uiHost->id, 'operation' => 'unlock'])->assertHasActionErrors(['operation']);
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-maintenance')->schemaComponent(true, 'content'), data: ['host_id' => $uiHost->id, 'operation' => 'check'])->assertHasNoActionErrors()->assertNotified('No eligible repositories');
check(Http::recorded()->isEmpty(), 'settings, invalid maintenance and empty maintenance do not contact nodes');

// Exercise the moved maintenance control through its real schema action, using
// native durable operations/queue dispatch and only a mocked node handshake.
$uiMaintenanceServer = Server::factory()->withNode()->create();
$uiMaintenanceBackup = Backup::factory()->create(['server_id' => $uiMaintenanceServer->id, 'backup_host_id' => $uiHost->id, 'is_successful' => true, 'completed_at' => now()]);
$uiHost->nodes()->attach($uiMaintenanceServer->node_id);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local']], 200,
    ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$uiMaintenanceServer->node->daemon_token_id.')']));
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-maintenance')->schemaComponent(true, 'content'), data: ['host_id' => $uiHost->id, 'operation' => 'check'])->assertHasNoActionErrors()->assertNotified('Maintenance queued');
check(DB::table('ibyte_restic_operations')->where('host_id', $uiHost->id)->where('backup_uuid', $uiMaintenanceBackup->uuid)->where('operation', 'check')->where('status', 'queued')->count() === 1, 'Administrative maintenance creates a durable operation for the selected linked host/server');
Livewire::test(RepositoryAdministration::class)->callAction(TestAction::make('host-maintenance')->schemaComponent(true, 'content'), data: ['host_id' => $uiHost->id, 'operation' => 'stats'])->assertHasNoActionErrors()->assertNotified('Maintenance was not fully queued');
check(DB::table('ibyte_restic_operations')->where('host_id', $uiHost->id)->where('backup_uuid', $uiMaintenanceBackup->uuid)->count() === 1, 'repeated central maintenance remains rate limited without queuing duplicate work');

Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::failedConnection());

// Explicit known/unknown/zero/deleted/current-generation measurement cases.
$uiRepo = $legacyRepo->replicate();
$uiRepo->fill(['host_id' => $uiHost->id, 'server_uuid' => (string) Str::uuid(), 'metadata' => ['host_name' => $uiHost->name, 'generation' => 1], 'server_deleted_at' => null])->save();
$uiMetrics = ['repository' => ['total_size' => 50, 'total_uncompressed_size' => 100, 'logical_bytes' => 400, 'snapshots_count' => 4, 'repository_file_bytes' => 75, 'compression_ratio' => 2, 'compression_space_saving' => 50, 'snapshots' => []]];
DB::table('ibyte_restic_operations')->insert(['request_uuid' => (string) Str::uuid(), 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $uiRepo->server_uuid, 'node_id' => $uiRepo->node_id, 'host_id' => $uiHost->id, 'operation' => 'stats', 'status' => 'succeeded', 'payload' => '{"generation":1}', 'metrics' => json_encode($uiMetrics), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
DB::table('ibyte_restic_operations')->insert(['request_uuid' => (string) Str::uuid(), 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $uiRepo->server_uuid, 'node_id' => $uiRepo->node_id, 'host_id' => $uiHost->id, 'operation' => 'stats', 'status' => 'succeeded', 'payload' => '{}', 'metrics' => json_encode(['repository' => array_merge($uiMetrics['repository'], ['repository_file_bytes' => 999999])]), 'created_at' => now(), 'updated_at' => now()]);
$uiStats = Livewire::test(RepositoryStatistics::class)->assertSee('Understanding storage')->assertSee('Measured at');
$uiTable = $uiStats->instance()->getTable();
check($uiTable->getColumn('dedup')->record($uiRepo)->getState() === '4.00x' && $uiTable->getColumn('compression')->record($uiRepo)->getState() === '2.00x', 'Stats calculates separate deduplication and compression ratios from saved blob sizes');
check($uiStats->instance()->measurement($uiRepo)['repository_file_bytes'] === 75 && $uiStats->instance()->measurement($uiRepo)['measured_at'] < now()->subDay()->toDateTimeString(), 'newer prior-generation metrics cannot replace the current generation or its honest old timestamp');
$uiMissing = $uiRepo->replicate();
$uiMissing->server_uuid = (string) Str::uuid();
$uiMissing->save();
check($uiStats->instance()->measurement($uiMissing) === null && $uiTable->getColumn('storage')->record($uiMissing)->getState() === 'Unavailable', 'missing repository measurement renders unavailable rather than fabricated zero');
$uiDeleted = $uiRepo->replicate();
$uiDeleted->server_uuid = (string) Str::uuid();
$uiDeleted->metadata = array_merge($uiDeleted->metadata, ['repository_deleted_at' => now()->toIso8601String()]);
$uiDeleted->save();
check($uiStats->instance()->measurement($uiDeleted) === null && $uiTable->getColumn('storage')->record($uiDeleted)->getState() === 'Removed', 'deleted repositories do not show their historical bytes as current storage');
check(RepositoryStatistics::ratio(0, 0) === 'Unavailable' && RepositoryStatistics::ratio(null, 10) === 'Unavailable', 'zero denominator and missing metrics never produce infinite or fabricated ratios');
$uiZero = $uiRepo->replicate();
$uiZero->server_uuid = (string) Str::uuid();
$uiZero->save();
$uiMetrics['repository'] = array_merge($uiMetrics['repository'], ['repository_file_bytes' => 0, 'total_size' => 0, 'total_uncompressed_size' => 0, 'logical_bytes' => 0]);
DB::table('ibyte_restic_operations')->insert(['request_uuid' => (string) Str::uuid(), 'backup_uuid' => (string) Str::uuid(), 'server_uuid' => $uiZero->server_uuid, 'node_id' => $uiZero->node_id, 'host_id' => $uiHost->id, 'operation' => 'stats', 'status' => 'succeeded', 'payload' => '{"generation":1}', 'metrics' => json_encode($uiMetrics), 'created_at' => now(), 'updated_at' => now()]);
check($uiTable->getColumn('storage')->record($uiZero)->getState() === convert_bytes_to_readable(0), 'an actual zero measurement remains visible as zero');
$uiDetails = Livewire::test(ListRecoveryRepositories::class)->mountTableAction('recovery-details', $uiRepo)->assertSet('mountedActions.0.name', 'recovery-details');
check(str_contains($uiDetails->getMountedActionModalHtml(), 'Repository identity') && str_contains($uiDetails->getMountedActionModalHtml(), 'Recent operations'), 'server details mounts a structured identity and operations modal');
check(Http::recorded()->isEmpty(), 'cached stats and structured server details never execute Restic or contact nodes');

auth()->login($generationServer->user);
foreach ($uiPages as $uiPage) {
    Livewire::test($uiPage)->assertStatus(403);
}
check(true, 'all three admin tab pages deny a server owner directly');
auth()->login($formAdmin);
$uiDemoted = Livewire::test(RepositoryAdministration::class);
auth()->login($generationServer->user);
$uiDemoted->call('$refresh')->assertStatus(403);
check(true, 'resource authorization is rechecked on Livewire hydration after administrator access is lost');
auth()->login($formAdmin);

Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($generationServer, isQuiet: true);
$uiClient = Livewire::test(ListBackups::class);
foreach ($uiRestricted as $uiAction) {
    $uiClient->assertTableActionDoesNotExist($uiAction, record: $uiBackup);
}
check(true, 'native customer backups never register admin keys or destructive overrides');
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
