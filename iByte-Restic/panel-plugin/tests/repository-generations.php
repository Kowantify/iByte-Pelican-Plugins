<?php

use App\Extensions\Tasks\Schemas\CreateBackupSchema;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\InitiateBackupService;
use Filament\Facades\Filament;
use IByte\Restic\Jobs\PollOperation;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\ForcedRepositoryRemoval;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Storage\StorageReport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

auth()->login($formAdmin);
config(['backups.throttles.period' => 0]);
$generationServer = Server::factory()->withNode()->create(['backup_limit' => 20]);
$generationHost = BackupHost::create(['name' => 'Automatic repository generations', 'schema' => 'restic', 'configuration' => ['profile' => 'default', 'backend' => 'local', 'staging_enabled' => true, 'restore_fixture_enabled' => true]]);
$generationHost->nodes()->attach($generationServer->node_id);
$generationExists = [0 => false];
$generationCapable = true;
$generationOffline = false;
$generationForged = false;
$dispatchedGenerations = [];
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($generationServer, &$generationExists, &$generationCapable, &$generationOffline, &$generationForged, &$dispatchedGenerations) {
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$generationServer->node->daemon_token_id.')'];
    $p = $request->data();
    $g = $p['generation'] ?? 0;
    if (str_ends_with($request->url(), '/readiness')) {
        return Http::response(['version' => '3.0.0-preview.2', 'protocol' => 1, 'ready' => true, 'profiles' => ['default' => 'local'], 'force_repository_delete' => 'local-v1', 'repository_generations' => $generationCapable ? 'local-v1' : null], 200, $headers);
    }
    if (str_ends_with($request->url(), '/repository-state')) {
        if ($generationOffline) {
            return Http::failedConnection();
        }
        $state = array_intersect_key($p, array_flip(['host', 'node', 'server', 'profile'])) + ['generation' => $g, 'exists' => $generationExists[$g] ?? false];
        if ($generationForged) {
            $state['generation']++;
        }

        return Http::response($state, 200, $headers);
    }
    if (str_ends_with($request->url(), '/recovery')) {
        return Http::response(array_replace(fixtureRecovery($p), ['password_base64' => base64_encode('disposable-test-password-generation-'.$g)]), 200, $headers);
    }
    if (str_ends_with($request->url(), '/server-key')) {
        if (isset($p['previous_generation'])) {
            check(! ($generationExists[$p['previous_generation']] ?? false), 'fresh key preparation follows verified old-generation absence');
        }

        return Http::response(['prepared' => true], 200, $headers);
    }
    if (($p['operation'] ?? null) === 'repo-force-delete') {
        $generationExists[$g] = false;
    }
    if (($p['operation'] ?? null) === 'create') {
        $current = RecoveryRepository::where('server_uuid', $generationServer->uuid)->firstOrFail();
        check(($current->metadata['generation'] ?? 0) === $g && DB::transactionLevel() === 0, 'replacement create dispatch uses committed escrow for its exact generation');
        $dispatchedGenerations[] = $g;
        $generationExists[$g] = true;
    }

    return Http::response($p + ['status' => 'succeeded', 'snapshot' => str_repeat('b', 64), 'bytes' => 10], 200, $headers);
});
$firstGeneration = app(InitiateBackupService::class)->setIgnoredFiles([])->handle($generationServer, 'Before repository deletion');
(new PollOperation($firstGeneration->uuid))->handle(app(Transport::class));
$generationRepo = RecoveryRepository::where('server_uuid', $generationServer->uuid)->firstOrFail();
$originalSecret = Crypt::decryptString($generationRepo->secret);
$originalPath = $generationRepo->metadata['repository'];
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
app(ForcedRepositoryRemoval::class)->run($formAdmin, $generationRepo, 'repository-fixture');
check($firstGeneration->fresh() === null && isset($generationRepo->fresh()->metadata['repository_deleted_at']), 'fixture reproduces explicitly deleted and retired repository with no native backups');
$generationCapable = false;
rejected(fn () => app(InitiateBackupService::class)->handle($generationServer, 'Old node refusal'), 'old node receives an update instruction instead of permanent new-host requirement', 'matching installer');
$generationCapable = true;
$generationOffline = true;
rejected(fn () => app(InitiateBackupService::class)->handle($generationServer, 'Offline absence refusal'), 'network errors cannot be interpreted as repository absence', 'repository state unavailable');
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($generationServer);
Livewire::test(ListBackups::class)->callTableAction('create', data: ['name' => 'Offline native generation', 'ignored' => '', 'is_locked' => false])->assertStatus(200);
check($generationServer->backups()->count() === 0, 'native offline generation failure returns a handled notification instead of HTTP500 or a phantom backup');
$generationOffline = false;
$generationForged = true;
rejected(fn () => app(InitiateBackupService::class)->handle($generationServer, 'Forged state refusal'), 'wrong-generation state cannot authorize new credentials', 'generation/absence');
$generationForged = false;
check(Crypt::decryptString($generationRepo->fresh()->secret) === $originalSecret, 'failed rollover preserves original encrypted key and location');
rejected(function () use ($generationServer) {
    DB::transaction(function () use ($generationServer) {
        app(InitiateBackupService::class)->handle($generationServer, 'Rolled back generation attempt');
        throw new RuntimeException('deliberate generation transaction rollback');
    });
}, 'failed surrounding creation transaction retains the previously escrowed generation', 'deliberate generation transaction rollback');
check(isset($generationRepo->fresh()->metadata['repository_deleted_at']) && $generationServer->backups()->count() === 0, 'rollover and native metadata roll back together before dispatch');
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($generationServer);
Livewire::test(ListBackups::class)->callTableAction('create', data: ['name' => 'After deleted repository', 'ignored' => '', 'is_locked' => false])->assertHasNoTableActionErrors();
$replacement = $generationServer->backups()->where('name', 'After deleted repository')->firstOrFail();
$g1 = $generationRepo->fresh();
$replacementSecret = Crypt::decryptString($g1->secret);
check($g1->metadata['generation'] === 1 && ! isset($g1->metadata['repository_deleted_at'], $g1->metadata['delete_request_uuid']) && $replacementSecret !== $originalSecret && $g1->metadata['repository'] !== $originalPath, 'native create automatically replaces retired repository with a fresh key/location on the same host');
check(json_decode(DB::table('ibyte_restic_operations')->where('request_uuid', $replacement->uuid)->value('payload'), true)['generation'] === 1, 'durable queued creation binds the replacement generation');
rejected(function () use ($generationServer) {
    DB::transaction(function () use ($generationServer) {
        app(InitiateBackupService::class)->handle($generationServer, 'Queued generation retry');
        throw new RuntimeException('deliberate prepared-generation rollback');
    });
}, 'retry before first node initialization reuses the prepared generation', 'deliberate prepared-generation rollback');
check(Crypt::decryptString($generationRepo->fresh()->secret) === $replacementSecret && $generationRepo->fresh()->metadata['generation'] === 1, 'pending first backup does not cause repeated key generations');
(new PollOperation($replacement->uuid))->handle(app(Transport::class));
check($replacement->fresh()->is_successful && $dispatchedGenerations === [0, 1], 'replacement backup completes through native polling without duplicate old-generation dispatch');
app(DaemonBackupRepository::class)->setServer($generationServer)->restore($replacement->fresh(), null, true);
$restore = DB::table('ibyte_restic_operations')->where('backup_uuid', $replacement->uuid)->where('operation', 'restore')->firstOrFail();
(new PollOperation($restore->request_uuid))->handle(app(Transport::class));
check(json_decode($restore->payload, true)['generation'] === 1 && DB::table('ibyte_restic_operations')->where('request_uuid', $restore->request_uuid)->value('status') === 'succeeded', 'native restore bridge/queue retain selected replacement generation');

// Out-of-band removal is detected on the next scheduled backup too.
$generationExists[1] = false;
$generationSchedule = Schedule::factory()->create(['server_id' => $generationServer->id]);
$generationTask = Task::factory()->create(['schedule_id' => $generationSchedule->id, 'action' => 'backup', 'payload' => '*.tmp']);
app(CreateBackupSchema::class)->runTask($generationTask);
$scheduledGeneration = $generationServer->backups()->where('is_scheduled', true)->firstOrFail();
(new PollOperation($scheduledGeneration->uuid))->handle(app(Transport::class));
$g2 = $generationRepo->fresh();
check($g2->metadata['generation'] === 2 && $scheduledGeneration->fresh()->is_successful && $scheduledGeneration->ignored_files === ['*.tmp'], 'native scheduled backup automatically renews a manually missing repository and respects ignore settings');
check((app(Transport::class)->context($replacement, false)['generation'] ?? 0) === 1, 'old backup metadata remains bound to its original generation after later renewal');
RateLimiter::clear('ibyte-restic:recovery-confirm:'.$formAdmin->id);
$url = RecoveryVault::issueReveal($formAdmin, $g2, 'repository-fixture', history: true);
parse_str(parse_url($url, PHP_URL_QUERY), $historyGrant);
$kit = RecoveryVault::kit($formAdmin, $g2, $historyGrant['token']);
check(count($kit['key_history']) === 3 && count(array_unique(array_column(array_column($kit['key_history'], 'repository'), 'repository'))) === 3, 'admin key history retains all three passwords with their distinct repository locations');
check(StorageReport::repository($g2) === null, 'fresh generation cannot show an older generation storage measurement as current');
Filament::setTenant(null, isQuiet: true);
Filament::setCurrentPanel(Filament::getPanel('admin'));
