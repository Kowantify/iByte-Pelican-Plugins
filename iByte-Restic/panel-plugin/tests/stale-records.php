<?php

use App\Enums\ServerState;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeletionService;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Backups\RecordRemoval;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

$cleanupAdmin = User::factory()->create(['password' => Hash::make('stale-fixture')]);
$cleanupAdmin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
$staleServer = Server::factory()->withNode()->create(['backup_limit' => 5]);
$staleHost = BackupHost::create(['name' => 'Deleted outside Panel fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
// Deliberately no node link, no recovery record and no successful node response.
$staleBackup = Backup::factory()->create(['server_id' => $staleServer->id, 'backup_host_id' => $staleHost->id, 'is_successful' => true, 'is_locked' => false, 'completed_at' => now()]);
$savedEscrow = $autoRepo->replicate();
$savedEscrow->fill(['host_id' => $staleHost->id, 'node_id' => $staleServer->node_id, 'server_uuid' => $staleServer->uuid])->save();
$savedSecret = $savedEscrow->getRawOriginal('secret');
DB::table('ibyte_restic_recovery_snapshots')->insert(['backup_uuid' => $staleBackup->uuid, 'repository_id' => $savedEscrow->id, 'snapshot_id' => str_repeat('a', 64), 'backup_name' => $staleBackup->name, 'owner_id' => $staleServer->owner_id, 'owner_username' => $staleServer->user->username, 'is_locked' => false, 'backup_created_at' => $staleBackup->created_at]);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::failedConnection());
app()->bind(ServerDeletionService::class, ServerDeletionService::class);
rejected(fn () => Compatibility::assertSupported(), 'reproduces supplied log binding failure without bypassing normal compatibility safety', 'safety binding is unavailable');
rejected(fn () => RecordRemoval::remove($staleServer->user, $staleBackup->uuid, $staleBackup->uuid, 'stale-fixture', true), 'customer cannot discard backup metadata');
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $staleBackup->uuid, 'wrong-uuid', 'stale-fixture', true), 'cleanup requires full exact backup UUID confirmation');
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $staleBackup->uuid, $staleBackup->uuid, 'stale-fixture', false), 'cleanup requires explicit external deletion confirmation');
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $staleBackup->uuid, $staleBackup->uuid, 'wrong-password', true), 'cleanup requires current root password');

Filament::setCurrentPanel(Filament::getPanel('admin'));
auth()->login($cleanupAdmin);
Schema::drop('ibyte_restic_recovery_audit');
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-repair-installation')->assertStatus(200);
check(Installation::missing() === [] && count(Http::recorded()) === 0, 'additive local table repair works with unavailable node binding and performs no node request');
rejected(fn () => Compatibility::assertSupported(), 'local repair does not disable normal node execution compatibility checks', 'safety binding is unavailable');
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-remove-record', data: [
    'backup_uuid' => $staleBackup->uuid, 'confirmation' => $staleBackup->uuid, 'password' => 'stale-fixture', 'external_deleted' => true,
])->assertHasNoActionErrors()->assertStatus(200);
check($staleBackup->fresh() === null, 'Restic Backups admin action removes external-deletion metadata despite missing node link and conflicting binding');
check(count(Http::recorded()) === 0, 'explicit metadata cleanup performs no readiness/recovery/delete/prune network calls');
$cleanupAudit = DB::table('ibyte_restic_operations')->where('backup_uuid', $staleBackup->uuid)->where('operation', 'record-remove')->firstOrFail();
check($cleanupAudit->status === 'dismissed' && json_decode($cleanupAudit->payload, true)['actor_id'] === $cleanupAdmin->id, 'cleanup preserves a durable UUID and actor audit instead of fabricating node deletion success');
check(! DB::table('ibyte_restic_operations')->where('server_uuid', $staleServer->uuid)->where('operation', 'prune')->exists(), 'record-only deletion cannot dispatch pruning through native deleted observers');
check(RecoveryRepository::findOrFail($savedEscrow->id)->getRawOriginal('secret') === $savedSecret
    && DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $staleBackup->uuid)->value('forgotten_at') !== null, 'external-deletion cleanup preserves encrypted escrow and snapshot history with an absence annotation');

$stalePending = Backup::factory()->create(['server_id' => $staleServer->id, 'backup_host_id' => $staleHost->id, 'is_successful' => false, 'is_locked' => false, 'completed_at' => null, 'created_at' => now()->subHours(4)]);
$pendingPayload = ['host' => $staleHost->id, 'node' => $staleServer->node_id, 'server' => $staleServer->uuid, 'backup' => $stalePending->uuid, 'profile' => 'default', 'operation' => 'create', 'request_id' => $stalePending->uuid];
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $stalePending->uuid, 'backup_uuid' => $stalePending->uuid, 'server_uuid' => $staleServer->uuid, 'node_id' => $staleServer->node_id, 'host_id' => $staleHost->id, 'operation' => 'create', 'payload' => json_encode($pendingPayload), 'status' => 'queued', 'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4)]);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
$staleServer->load('backups');
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-remove-record', data: [
    'backup_uuid' => $stalePending->uuid, 'password' => 'stale-fixture',
])->assertHasNoActionErrors()->assertStatus(200);
check($stalePending->fresh() === null && DB::table('ibyte_restic_operations')->where('request_uuid', $stalePending->uuid)->value('status') === 'dismissed', 'native row cleanup handles frozen old in-progress records and dismisses stale polling');
check($staleServer->fresh()->backups->count() === 0, 'native backup count reflects admin removal of a stale pending row');
(new PollOperation($stalePending->uuid))->handle(app(Transport::class));
(new PollOperation($stalePending->uuid))->failed(new RuntimeException('late old worker failure'));
check(count(Http::recorded()) === 0 && DB::table('ibyte_restic_operations')->where('request_uuid', $stalePending->uuid)->value('status') === 'dismissed', 'late queued polling or failed hook cannot resurrect a dismissed record or contact node');

$protected = Backup::factory()->create(['server_id' => $staleServer->id, 'backup_host_id' => $staleHost->id, 'is_successful' => true, 'is_locked' => true, 'completed_at' => now()]);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $protected->uuid, $protected->uuid, 'stale-fixture', true), 'metadata cleanup preserves locked backup protection', 'Unlock');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$cleanupAdmin->id);
DB::table('backups')->where('id', $protected->id)->update(['is_locked' => false, 'is_successful' => false, 'completed_at' => null]);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $protected->uuid, $protected->uuid, 'stale-fixture', true), 'metadata cleanup refuses recent in-progress backup work', 'Recent backup work');
DB::table('backups')->where('id', $protected->id)->update(['completed_at' => now()]);
DB::table('servers')->where('id', $staleServer->id)->update(['status' => ServerState::RestoringBackup->value]);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $protected->uuid, $protected->uuid, 'stale-fixture', true), 'metadata cleanup never clears unresolved restore protection', 'Restore state');
DB::table('servers')->where('id', $staleServer->id)->update(['status' => null]);
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $protected->uuid, 'backup_uuid' => $protected->uuid, 'server_uuid' => $staleServer->uuid, 'node_id' => $staleServer->node_id, 'host_id' => $staleHost->id, 'operation' => 'create', 'payload' => '{}', 'status' => 'running', 'created_at' => now()->subHours(4), 'updated_at' => now()]);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $protected->uuid, $protected->uuid, 'stale-fixture', true), 'an old operation with a fresh running heartbeat is still protected', 'Recent backup work');
DB::table('ibyte_restic_operations')->where('request_uuid', $protected->uuid)->update(['operation' => 'restore', 'status' => 'unknown']);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $protected->uuid, $protected->uuid, 'stale-fixture', true), 'unknown restore job protects the record even if native server status was cleared externally', 'Restore state');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$cleanupAdmin->id);
$otherHost = BackupHost::create(['name' => 'Non-Restic cleanup exclusion', 'schema' => 'wings', 'configuration' => []]);
$otherBackup = Backup::factory()->create(['server_id' => $staleServer->id, 'backup_host_id' => $otherHost->id, 'completed_at' => now()]);
rejected(fn () => RecordRemoval::remove($cleanupAdmin, $otherBackup->uuid, $otherBackup->uuid, 'stale-fixture', true), 'Restic cleanup cannot remove a Wings backup record');
auth()->login($staleServer->user);
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($staleServer);
Livewire::test(ListBackups::class)->assertTableActionDoesNotExist('ibyte-restic-remove-record', record: $protected);
check($otherBackup->fresh() !== null && count(Http::recorded()) === 0, 'customer sees no cleanup action and non-Restic metadata and node storage remain untouched');
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));
app()->bind(ServerDeletionService::class, GuardedServerDeletion::class);
auth()->logout();
