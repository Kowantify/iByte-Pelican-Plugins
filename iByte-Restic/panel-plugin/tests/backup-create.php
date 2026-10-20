<?php

// Real native server Backups action, against the isolated SQLite application.
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\BackupHost;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use IByte\Restic\Jobs\PollOperation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

$actionServer = Server::factory()->withNode()->create(['backup_limit' => 3]);
$actionHost = BackupHost::create([
    'name' => 'Native backup action fixture', 'schema' => 'restic',
    'configuration' => ['backend' => 'local', 'profile' => 'default', 'staging_enabled' => false, 'restore_fixture_enabled' => false],
]);
$actionHost->nodes()->attach($actionServer->node_id);
config(['queue.default' => 'database', 'backups.throttles.period' => 0]);
// Each Http::fake appends callbacks; reset the factory so prior node fixtures
// cannot accidentally answer this new node's readiness request.
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/recovery') ? fixtureRecovery($request->data()) : [
    'ready' => true, 'version' => '3.0.0-preview.2', 'protocol' => 1, 'profiles' => ['default' => 'local'],
], 200, ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$actionServer->node->daemon_token_id.')']));

Filament::setCurrentPanel(Filament::getPanel('server'));
Auth::login($actionServer->user);
Filament::setTenant($actionServer);
$beforeOperations = DB::table('ibyte_restic_operations')->count();
session()->forget('filament.notifications');
$denied = Livewire::test(ListBackups::class)
    ->callTableAction('create', data: ['name' => 'Disabled host must reject', 'ignored' => '', 'is_locked' => false])
    ->assertStatus(200);
check($denied->instance()->getErrorBag()->isEmpty(), 'disabled Restic host returns a handled native Livewire action response instead of HTTP 500');
$notifications = new Notifications;
$notifications->mount();
$notice = $notifications->notifications->last()?->toArray();
check(($notice['status'] ?? '') === 'danger'
    && str_contains($notice['body'] ?? '', 'Enable preview on disposable staging servers'), 'native backup failure notification explains the exact administrator opt-in setting');
check($actionServer->backups()->count() === 0 && DB::table('ibyte_restic_operations')->count() === $beforeOperations, 'disabled host rolls back backup metadata and creates no daemon operation');
Http::assertNothingSent();
check(true, 'disabled host is rejected before any Wings request');

try {
    app(InitiateBackupService::class)->setIgnoredFiles([])->handle($actionServer, 'Disabled service path');
    throw new RuntimeException('Disabled host unexpectedly accepted');
} catch (HttpException $exception) {
    check($exception->getStatusCode() === 409 && $exception->getHeaders()['Retry-After'] === 0, 'disabled host is a safe HTTP conflict for native API/service callers');
}

$actionHost->update(['configuration' => array_merge($actionHost->configuration, ['staging_enabled' => true])]);
session()->forget('filament.notifications');
$enabled = Livewire::test(ListBackups::class)
    ->callTableAction('create', data: ['name' => 'Native enabled create', 'ignored' => '', 'is_locked' => false])
    ->assertStatus(200);
$nativeCreated = $actionServer->backups()->where('name', 'Native enabled create')->firstOrFail();
check($nativeCreated->backup_host_id === $actionHost->id && ! $nativeCreated->completed_at && ! $nativeCreated->is_successful, 'actual native Create Backup action queues an enabled Restic backup without claiming completion');
check(DB::table('ibyte_restic_operations')->where('request_uuid', $nativeCreated->uuid)->where('status', 'queued')->exists(), 'native backup action persists its correlated durable operation');
Bus::assertDispatched(PollOperation::class, fn ($job) => $job->requestUuid === $nativeCreated->uuid);
check(true, 'native backup action dispatches its queue job');
check($actionHost->fresh()->configuration['restore_fixture_enabled'] === false, 'enabling backup preview does not enable restore');
Auth::logout();
Filament::setTenant(null, isQuiet: true);
