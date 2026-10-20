<?php

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Console\DiagnoseNode;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Support\FailureAlerts;
use IByte\Restic\Support\Installation;
use IByte\Restic\Support\NodeDiagnostics;
use IByte\Restic\Support\OperationDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

auth()->login($formAdmin);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
$diagnosticServer = Server::factory()->withNode()->create();
$diagnosticServer->node->update(['scheme' => 'https']);
$diagnosticHost = BackupHost::create(['name' => 'Node diagnostic fixture', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true]]);
$diagnosticHost->nodes()->attach($diagnosticServer->node_id);
$diagnosticBackup = Backup::factory()->create(['server_id' => $diagnosticServer->id, 'backup_host_id' => $diagnosticHost->id]);
$diagnosticRequest = (string) Str::uuid();
$diagnosticPayload = ['host' => $diagnosticHost->id, 'node' => $diagnosticServer->node_id, 'server' => $diagnosticServer->uuid, 'backup' => $diagnosticBackup->uuid,
    'profile' => 'default', 'backend' => 'local', 'operation' => 'check', 'request_id' => $diagnosticRequest];
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $diagnosticRequest, 'host_id' => $diagnosticHost->id, 'node_id' => $diagnosticServer->node_id,
    'server_uuid' => $diagnosticServer->uuid, 'backup_uuid' => $diagnosticBackup->uuid, 'operation' => 'check', 'status' => 'running', 'payload' => json_encode($diagnosticPayload), 'created_at' => now(), 'updated_at' => now()]);
$diagnosticMode = 'healthy';
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($diagnosticServer, &$diagnosticMode) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$diagnosticServer->node->daemon_token_id.')'];
    if ($diagnosticMode === 'wrong-identity') {
        $headers['User-Agent'] = 'untrusted-secret-fixture';
    }
    if ($diagnosticMode === 'auth-failure') {
        return Http::response(['error' => 'private-fixture-secret'], 401, $headers);
    }
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'recovery_vault' => true,
            'profiles' => ['default' => $diagnosticMode === 'wrong-profile' ? 's3' : 'local'], 'password' => 'private-fixture-secret'], 200, $headers);
    }
    if (str_ends_with($request->url(), '/server-key')) {
        return Http::response(['error' => 'private-fixture-secret'], 409, $headers);
    }
    $state = $request->data() + ['status' => 'failed', 'error' => $diagnosticMode === 'arbitrary-error' ? 'private-fixture-secret' : 'restic check failed: exit status 1 (output redacted)'];
    if ($diagnosticMode === 'forged-result') {
        $state['server'] = (string) Str::uuid();
    }

    return Http::response($state, 200, $headers);
});

$diag = NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id, $diagnosticRequest);
check($diag['ready'] && $diag['operation']['node_status'] === 'failed' && str_contains($diag['operation']['details'], 'check exited with status 1'), 'read-only diagnostics retrieve the real saved failure category rather than a generic historical message');
check(! str_contains(json_encode($diag), 'private-fixture-secret'), 'readiness and job diagnostics never expose arbitrary node response fields');
$beforeDiagnosticRows = hash('sha256', json_encode(DB::table('ibyte_restic_operations')->get()));
NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id, $diagnosticRequest);
check(hash('sha256', json_encode(DB::table('ibyte_restic_operations')->get())) === $beforeDiagnosticRows, 'node inspection does not change historical status, receipts or protection');
check(collect(Http::recorded())->every(fn ($pair) => $pair[0]->method() === 'GET' || str_ends_with($pair[0]->url(), '/status')), 'diagnostics only call readiness and read-only status routes, never jobs or key endpoints');
$diagnosticMode = 'wrong-profile';
check(NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id)['checks']['profile_matches'] === false, 'node diagnostics identify backend/profile mismatch');
$diagnosticMode = 'auth-failure';
$diag = NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id);
check(! $diag['ready'] && str_contains($diag['problem'], 'HTTP 401') && ! str_contains(json_encode($diag), 'private-fixture-secret'), 'authentication failure reports the HTTP category without leaking its body');
$diagnosticMode = 'wrong-identity';
check(str_contains(NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id)['problem'], 'identity/version'), 'wrong node identity is rejected before accepting readiness');
$diagnosticMode = 'forged-result';
check(isset(NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id, $diagnosticRequest)['operation']['problem']), 'saved result with forged server identity is rejected');
$diagnosticMode = 'arbitrary-error';
$diag = NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id, $diagnosticRequest);
check(! str_contains(json_encode($diag), 'private-fixture-secret') && str_contains($diag['operation']['details'], 'No recognized safe'), 'unknown node error remains redacted without inventing a failure reason');
$diagnosticMode = 'healthy';
$beforeUnlinked = count(Http::recorded());
$diagnosticHost->nodes()->detach($diagnosticServer->node_id);
check(! NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id)['ready'] && count(Http::recorded()) === $beforeUnlinked, 'unlinked node diagnostic is refused without network traffic');
$diagnosticHost->nodes()->attach($diagnosticServer->node_id);
$diagnosticServer->node->update(['scheme' => 'http']);
check(! NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id)['ready'] && count(Http::recorded()) === $beforeUnlinked, 'HTTP node diagnostics are refused without fetching credentials');
$diagnosticServer->node->update(['scheme' => 'https']);

$diagUi = Livewire::test(RepositoryAdministration::class)->callAction('node-diagnostics', data: ['host_id' => $diagnosticHost->id, 'node_id' => $diagnosticServer->node_id, 'operation_id' => $diagnosticRequest])->assertStatus(200)->assertHasNoActionErrors();
check($diagUi->get('nodeDiagnosticReport')['ready'] && str_contains($diagUi->getMountedActionModalHtml(), 'check exited with status 1'), 'actual root admin modal renders saved diagnostics and remains open for review');
auth()->login($stranger);
Livewire::test(RepositoryAdministration::class)->assertStatus(403);
check(true, 'non-root cannot access node diagnostic controls');
auth()->login($formAdmin);

$escrowCount = DB::table('ibyte_restic_repositories')->count();
try {
    RecoveryVault::capture($diagnosticBackup);
    throw new RuntimeException('Escrow refusal was expected');
} catch (HttpException $exception) {
    check($exception->getStatusCode() === 409 && str_contains($exception->getMessage(), 'key preparation') && str_contains($exception->getMessage(), 'HTTP 409') && ! str_contains($exception->getMessage(), 'private-fixture-secret') && $exception->getPrevious() === null, 'escrow failure names its exact stage and safe HTTP category without chaining a secret response');
}
check(DB::table('ibyte_restic_repositories')->count() === $escrowCount && DB::table('ibyte_restic_operations')->where('request_uuid', $diagnosticRequest)->value('status') === 'running', 'failed escrow does not create a new vault entry or dispatch/mutate the diagnostic operation');

(new PollOperation($diagnosticRequest))->handle(app(Transport::class));
$diagRow = DB::table('ibyte_restic_operations')->where('request_uuid', $diagnosticRequest)->first();
check($diagRow->status === 'failed' && str_contains($diagRow->error, 'check exited with status 1'), 'normal completion worker persists only the recognized safe failure category');
$delivered = DB::table('notifications')->where('data', 'like', '%'.$diagnosticRequest.'%');
check($diagRow->alerts_delivered_at !== null && (clone $delivered)->exists(), 'terminal failure notification and durable receipt are committed together');
$notice = json_decode((clone $delivered)->first()->data, true);
check(str_contains($notice['title'], 'integrity check') && str_contains($notice['body'], $diagnosticRequest), 'notifications identify operation type and exact operation ID');
$ownNotice = (clone $delivered)->where('notifiable_id', $formAdmin->id)->first();
Livewire::test(DatabaseNotifications::class)->call('removeNotification', $ownNotice->id)->assertStatus(200);
FailureAlerts::dispatch();
check(DB::table('notifications')->where('id', $ownNotice->id)->doesntExist(), 'actual native close-notification action stays dismissed after scheduler replay');
(clone $delivered)->delete();
FailureAlerts::operation($diagnosticRequest);
FailureAlerts::dispatch();
check((clone $delivered)->doesntExist(), 'clearing delivered notifications does not recreate them on direct replay or scheduler catch-up');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $diagnosticRequest)->value('status') === 'failed', 'dismissed notification does not hide or resolve the actual failed operation');
$recentDiagnostic = collect(FailureAlerts::recent())->firstWhere('request_uuid', $diagnosticRequest);
check(str_contains($recentDiagnostic['details'], 'check exited with status 1') && ! array_key_exists('error', $recentDiagnostic), 'Needs attention shows safe saved failure details instead of arbitrary historical error text');
check(! str_contains(OperationDiagnostics::storedFailure('Restic check exited with status 1. private-fixture-secret'), 'private-fixture-secret'), 'forged canonical error prefixes cannot bypass failure-detail redaction');

$retainedDiagnosticRequest = (string) Str::uuid();
$retainedDiagnosticPayload = array_replace($diagnosticPayload, ['request_id' => $retainedDiagnosticRequest, 'repository_fingerprint' => str_repeat('a', 64)]);
DB::table('ibyte_restic_operations')->insert(['request_uuid' => $retainedDiagnosticRequest, 'host_id' => $diagnosticHost->id, 'node_id' => $diagnosticServer->node_id,
    'server_uuid' => $diagnosticServer->uuid, 'backup_uuid' => $diagnosticBackup->uuid, 'operation' => 'check', 'status' => 'failed', 'payload' => json_encode($retainedDiagnosticPayload), 'created_at' => now(), 'updated_at' => now()]);
$retainedDiag = NodeDiagnostics::inspect($diagnosticHost->id, $diagnosticServer->node_id, $retainedDiagnosticRequest);
check($retainedDiag['operation']['node_status'] === 'failed' && collect(Http::recorded())->contains(fn ($pair) => str_ends_with($pair[0]->url(), '/repository-maintenance/status')), 'retained diagnostics use the upstream-verified status handler rather than resubmitting maintenance');
DB::beginTransaction();
FailureAlerts::operation($retainedDiagnosticRequest);
DB::rollBack();
check(DB::table('ibyte_restic_operations')->where('request_uuid', $retainedDiagnosticRequest)->value('alerts_delivered_at') === null && DB::table('notifications')->where('data', 'like', '%'.$retainedDiagnosticRequest.'%')->doesntExist(), 'rollback preserves atomic delivery receipt and notification semantics');
FailureAlerts::operation($retainedDiagnosticRequest);
$oldNotice = DB::table('notifications')->where('data', 'like', '%'.$retainedDiagnosticRequest.'%')->where('notifiable_id', $formAdmin->id)->first();
$oldData = json_decode($oldNotice->data, true);
$oldData['title'] = 'Restic operation needs attention';
DB::table('notifications')->where('id', $oldNotice->id)->update(['data' => json_encode($oldData), 'read_at' => now()]);
DB::table('ibyte_restic_operations')->where('request_uuid', $retainedDiagnosticRequest)->update(['alerts_delivered_at' => null]);
FailureAlerts::operation($retainedDiagnosticRequest);
$upgradedNotice = DB::table('notifications')->where('id', $oldNotice->id)->first();
check($upgradedNotice->read_at !== null && $upgradedNotice->created_at === $oldNotice->created_at && str_contains(json_decode($upgradedNotice->data, true)['title'], 'integrity check'), 'pre-upgrade generic notification receives operation labels without changing its date or read acknowledgement');

Schema::table('ibyte_restic_operations', fn ($table) => $table->dropColumn('alerts_delivered_at'));
check(Installation::missing() === ['ibyte_restic_operations.alerts_delivered_at'], 'missing alert receipt column is detected without pretending installation is complete');
Installation::repair();
check(Installation::missing() === [] && DB::table('ibyte_restic_operations')->where('request_uuid', $diagnosticRequest)->value('status') === 'failed', 'additive installation repair restores a missing receipt column despite logged migration and keeps failure history');

$command = app(DiagnoseNode::class);
app(Kernel::class)->registerCommand($command);
check(Artisan::call('restic:diagnose-node', ['host' => (string) $diagnosticHost->id, 'node' => (string) $diagnosticServer->node_id]) === 0, 'CLI read-only node diagnosis succeeds for matching node/profile without requesting keys');
