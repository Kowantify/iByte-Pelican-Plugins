<?php

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RecoveryResource;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryRevealController;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\ResticPlugin;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

check(Schema::hasTable('ibyte_restic_repositories') && Schema::hasTable('ibyte_restic_recovery_snapshots'), 'native ZIP installer creates durable recovery tables');
$recoveryServer = Server::factory()->withNode()->create(['name' => '<script>world</script>', 'backup_limit' => 10]);
$firstOwner = $recoveryServer->user;
$recoveryHost = BackupHost::create(['name' => 'Recovery fixture', 'schema' => 'restic', 'configuration' => ['backend' => 'local', 'profile' => 'test', 'staging_enabled' => true, 'restore_fixture_enabled' => false]]);
$recoveryHost->nodes()->attach($recoveryServer->node_id);
$recoveryBackup = Backup::factory()->create(['server_id' => $recoveryServer->id, 'backup_host_id' => $recoveryHost->id, 'completed_at' => null, 'is_successful' => false, 'is_locked' => true]);
$escrowOffline = false;
$node = $recoveryBackup->server->node;
$node->scheme = 'http';
$httpBefore = count(Http::recorded());
rejected(fn () => RecoveryVault::capture($recoveryBackup), 'escrow refuses plaintext HTTP before requesting any key', 'requires trusted HTTPS');
check(count(Http::recorded()) === $httpBefore, 'HTTP refusal never transmits repository credentials');
$node->scheme = 'https';
$changedKey = false;
$forgedEscrow = false;
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($recoveryServer, &$escrowOffline, &$changedKey, &$forgedEscrow) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$recoveryServer->node->daemon_token_id.')'];
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['test' => 'local']], 200, $headers);
    }
    $p = $request->data();
    if (str_ends_with($request->url(), '/snapshot-proof')) {
        return Http::response($p + ['snapshot' => str_repeat('d', 64), 'deleted' => false], 200, $headers);
    }
    if (str_ends_with($request->url(), '/recovery')) {
        if ($escrowOffline) {
            return Http::failedConnection();
        }
        $info = fixtureRecovery($p);
        if ($changedKey) {
            $info['password_base64'] = base64_encode('changed-fixture-value');
        }
        if ($forgedEscrow) {
            $info['server'] = 'wrong';
        }

        return Http::response($info, 200, $headers);
    }

    return Http::response($p + ['status' => 'succeeded', 'snapshot' => str_repeat('d', 64), 'bytes' => 200, 'storage' => ['total_bytes_processed' => 200, 'data_added' => 100, 'data_added_packed' => 80]], 200, $headers);
});
app(Operations::class)->queue($recoveryBackup, 'create', $recoveryBackup->uuid);
$vault = RecoveryRepository::where('server_uuid', $recoveryServer->uuid)->firstOrFail();
$stored = DB::table('ibyte_restic_repositories')->where('id', $vault->id)->value('secret');
check(! str_contains($stored, 'disposable-test-password') && ! str_contains($stored, base64_encode('disposable-test-password')) && ! array_key_exists('secret', $vault->toArray()), 'escrow is encrypted at rest and excluded from normal model JSON');
check($vault->owner_id === $firstOwner->id && $vault->metadata['repository'] === '/srv/disposable-restic/host-'.$recoveryHost->id.'/node-'.$recoveryServer->node_id.'/'.$recoveryServer->uuid, 'repository inventory records original username/ID and exact default-layout location');
(new PollOperation($recoveryBackup->uuid))->handle(app(Transport::class));
$snapshot = DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $recoveryBackup->uuid)->first();
check($snapshot && $snapshot->snapshot_id === str_repeat('d', 64) && $snapshot->owner_username === $firstOwner->username, 'verified completion preserves snapshot mapping and original owner independently of native backup row');
$newOwner = User::factory()->create();
$recoveryServer->update(['owner_id' => $newOwner->id]);
$vault->refresh();
check(count($vault->owner_history) === 2 && $vault->owner_history[0]['owner']['id'] === $firstOwner->id && $vault->owner_id === $newOwner->id, 'ownership change preserves former-owner history rather than overwriting identity');
$recoveryBackup->unsetRelation('server');
$recoveryServer->refresh();
$changedKey = true;
rejected(fn () => RecoveryVault::capture($recoveryBackup), 'external key change cannot silently overwrite historical escrow', 'preserved');
$changedKey = false;
$forgedEscrow = true;
rejected(fn () => RecoveryVault::capture($recoveryBackup), 'forged repository/server credential response rejected', 'escrow unavailable');
$forgedEscrow = false;
$escrowOffline = true;
rejected(fn () => RecoveryVault::capture($recoveryBackup), 'offline recovery endpoint returns redacted actionable failure', 'escrow unavailable');
$escrowOffline = false;

$formAdmin->update(['password' => Hash::make('recovery-fixture')]);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => RecoveryVault::issueReveal($firstOwner, $vault, 'recovery-fixture'), 'ordinary owner cannot issue key reveal even knowing an admin password');
rejected(fn () => RecoveryVault::issueReveal($formAdmin, $vault, 'wrong'), 'root key reveal requires current password', 'incorrect');

// Testing mode skips native plugin discovery. Invoke the real Plugin::register
// and Filament resource route registration for this disposable Livewire fixture.
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null);
auth()->login($formAdmin);
(new ResticPlugin)->register(Filament::getPanel('admin'));
Route::prefix('admin')->name('filament.admin.')->group(fn () => RecoveryResource::registerRoutes(Filament::getPanel('admin')));
app('router')->getRoutes()->refreshNameLookups();
check(in_array(RecoveryResource::class, Filament::getPanel('admin')->getResources(), true) && ! in_array(RecoveryResource::class, Filament::getPanel('server')->getResources(), true), 'plugin registers View Restic keys resource only in native admin panel');
$listing = Livewire::test(RepositoryAdministration::class)->assertCanSeeTableRecords([$vault]);
check(! str_contains($listing->html(), base64_encode('disposable-test-password')) && ! str_contains($listing->html(), 'disposable-test-password'), 'actual native inventory list renders metadata without plaintext/base64 credentials');
$action = Livewire::test(RepositoryAdministration::class)->callTableAction('view-restic-keys', $vault, data: ['password' => 'recovery-fixture'])->assertRedirect();
check(! str_contains($action->html(), 'disposable-test-password') && ! str_contains($action->html(), base64_encode('disposable-test-password')), 'Livewire action state never receives the revealed repository password');
$url = $action->effects['redirect'];
parse_str(parse_url($url, PHP_URL_QUERY), $params);
$plainReveal = Request::create(preg_replace('/^https:/', 'http:', $url));
$plainReveal->setUserResolver(fn () => $formAdmin);
rejected(fn () => (new RecoveryRevealController)($plainReveal, $vault), 'key endpoint refuses plaintext Panel HTTP before consuming reveal grant', 'HTTPS is required');
$reveal = Request::create(preg_replace('/^http:/', 'https:', $url));
$reveal->setUserResolver(fn () => $formAdmin);
$response = (new RecoveryRevealController)($reveal, $vault);
check(str_contains($response->getContent(), 'disposable-test-password') && str_contains($response->getContent(), '&lt;script&gt;world&lt;/script&gt;') && ! str_contains($response->getContent(), '<script>world'), 'password-confirmed native action reveals key in separate HTML response with escaped metadata');
check(str_contains($response->headers->get('Cache-Control'), 'no-store') && $response->headers->get('Referrer-Policy') === 'no-referrer' && str_contains($response->headers->get('Content-Security-Policy'), "default-src 'none'"), 'secret reveal response forbids caching, referrer leakage and embedded scripts');
rejected(fn () => (new RecoveryRevealController)($reveal, $vault), 'consumed reveal token cannot be replayed');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$secondUrl = RecoveryVault::issueReveal($formAdmin, $vault, 'recovery-fixture');
parse_str(parse_url($secondUrl, PHP_URL_QUERY), $secondParams);
rejected(fn () => RecoveryVault::kit($firstOwner, $vault, $secondParams['token']), 'direct key endpoint rejects non-root server owner');
$otherRoot = User::factory()->create();
$otherRoot->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
rejected(fn () => RecoveryVault::kit($otherRoot, $vault, $secondParams['token']), 'reveal grant is bound to the requesting root admin');
$recoveryOriginalClock = Carbon\Carbon::getTestNow();
Carbon\Carbon::setTestNow(now()->addSeconds(31));
rejected(fn () => RecoveryVault::kit($formAdmin, $vault, $secondParams['token']), 'reveal grant expires after thirty seconds');
Carbon\Carbon::setTestNow($recoveryOriginalClock);
check(! str_contains(json_encode(DB::table('ibyte_restic_recovery_audit')->get()), 'disposable-test-password') && DB::table('ibyte_restic_recovery_audit')->where('event', 'key-viewed')->exists(), 'key views are audited without recording secret values');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$downloadAction = Livewire::test(RepositoryAdministration::class)->callTableAction('download-recovery-kit', $vault, data: ['password' => 'recovery-fixture'])->assertRedirect();
$downloadRequest = Request::create(preg_replace('/^http:/', 'https:', $downloadAction->effects['redirect']));
$downloadRequest->setUserResolver(fn () => $formAdmin);
$kitDownload = (new RecoveryRevealController)($downloadRequest, $vault);
check(str_contains($kitDownload->headers->get('Content-Disposition'), 'attachment;') && json_decode($kitDownload->getContent(), true)['secret']['password_base64'] === base64_encode('disposable-test-password') && str_contains($kitDownload->headers->get('Cache-Control'), 'no-store'), 'native password-confirmed action downloads a usable noncached recovery JSON kit');

RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$beforeCalls = count(Http::recorded());
fixturePreparedFinal($recoveryServer, $recoveryBackup, $formAdmin);
rejected(fn () => app(RetainedDeletion::class)->run($firstOwner, $recoveryServer, 'recovery-fixture', $recoveryServer->uuid), 'non-root cannot retain/delete server');
rejected(fn () => app(RetainedDeletion::class)->run($formAdmin, $recoveryServer, 'recovery-fixture', 'wrong'), 'retain/delete requires exact server UUID confirmation', 'does not match');
check(count(Http::recorded()) === $beforeCalls, 'failed retain/delete authorization makes no daemon calls');
$escrowOffline = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RetainedDeletion::class)->run($formAdmin, $recoveryServer, 'recovery-fixture', $recoveryServer->uuid), 'retained deletion refuses unavailable fresh escrow before deleting server', 'escrow unavailable');
$escrowOffline = false;
check($recoveryServer->fresh() !== null && ! app(RetainedDeletion::class)->active($recoveryServer), 'failed retained-delete preflight preserves server and closes scope');
$failingDaemon = Mockery::mock(DaemonServerRepository::class);
$failingDaemon->shouldReceive('setServer')->once()->andReturnSelf();
$failingDaemon->shouldReceive('delete')->once()->andThrow(new Error('fixture daemon failure'));
app()->instance(DaemonServerRepository::class, $failingDaemon);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
rejected(fn () => app(RetainedDeletion::class)->run($formAdmin, $recoveryServer, 'recovery-fixture', $recoveryServer->uuid), 'native retained-delete failure is propagated without faking success', 'fixture daemon failure');
check(! app(RetainedDeletion::class)->active($recoveryServer) && RecoveryRepository::whereKey($vault->id)->exists() && $recoveryServer->fresh() !== null, 'native failure closes retention scope and preserves committed recovery escrow');
Mockery::close();
app()->forgetInstance(DaemonServerRepository::class);
$daemon = Mockery::mock(DaemonServerRepository::class);
$daemon->shouldReceive('setServer')->once()->andReturnSelf();
$daemon->shouldReceive('delete')->once()->andReturnNull();
app()->instance(DaemonServerRepository::class, $daemon);
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
app(RetainedDeletion::class)->run($formAdmin, $recoveryServer, 'recovery-fixture', $recoveryServer->uuid);
Mockery::close();
app()->forgetInstance(DaemonServerRepository::class);
$vault->refresh();
check($recoveryServer->fresh() === null && ! Backup::where('uuid', $recoveryBackup->uuid)->exists() && $vault->server_deleted_at !== null, 'explicit retained deletion uses native cascade while preserving archived repository inventory');
Http::assertNotSent(fn ($request) => ($request->data()['operation'] ?? '') === 'delete');
check(DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $recoveryBackup->uuid)->whereNull('forgotten_at')->exists(), 'retained deletion never sends Restic forget and keeps snapshot mapping');
check((bool) DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $recoveryBackup->uuid)->value('is_locked'), 'retained deletion preserves locked snapshot metadata without unlocking or forgetting it');
check(! app(RetainedDeletion::class)->active($recoveryServer), 'retained deletion scope always closes');
$firstOwner->delete();
$newOwner->delete();
$escrowOffline = true;
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$archivedUrl = RecoveryVault::issueReveal($formAdmin, $vault, 'recovery-fixture');
parse_str(parse_url($archivedUrl, PHP_URL_QUERY), $archivedParams);
$archivedKit = RecoveryVault::kit($formAdmin, $vault, $archivedParams['token']);
check($archivedKit['secret']['password_base64'] === base64_encode('disposable-test-password') && $archivedKit['identity']['owner_history'][0]['owner']['username'] === $firstOwner->username && count($archivedKit['snapshots']) === 1, 'former owner/server deletion and offline node do not prevent key and snapshot recovery');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
for ($attempt = 0; $attempt < 5; $attempt++) {
    try {
        RecoveryVault::confirm($formAdmin, 'wrong');
    } catch (ValidationException) {
    }
}
rejected(fn () => RecoveryVault::issueReveal($formAdmin, $vault, 'recovery-fixture'), 'password-confirmation brute force is rate limited even for authenticated root admin');
rejected(fn () => (require dirname(__DIR__).'/database/migrations/2026_10_15_000002_create_ibyte_restic_recovery_inventory.php')->down(), 'uninstall cannot discard retained recovery inventory', 'preserved');
$profileServer = Server::factory()->withNode()->create();
$wrongProfile = $vault->replicate();
$wrongProfile->fill(['server_uuid' => $profileServer->uuid, 'node_id' => $profileServer->node_id, 'profile' => 'alternate', 'server_deleted_at' => null])->save();
$rightProfile = $wrongProfile->replicate();
$rightProfile->profile = 'test';
$rightProfile->save();
$profileBackup = Backup::factory()->create(['server_id' => $profileServer->id, 'backup_host_id' => $recoveryHost->id]);
RecoveryVault::snapshot($profileBackup, str_repeat('e', 64), []);
check((int) DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $profileBackup->uuid)->value('repository_id') === $rightProfile->id, 'snapshot escrow binds the exact credential profile when multiple repository histories coexist');
auth()->login($stranger);
Livewire::test(RepositoryAdministration::class)->assertStatus(403);
check(! RecoveryResource::canViewAny(), 'actual native recovery inventory page denies a non-root user');
auth()->login($formAdmin);
