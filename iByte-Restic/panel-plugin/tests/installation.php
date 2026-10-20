<?php

use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Services\Backups\DeleteBackupService;
use Filament\Facades\Filament;
use IByte\Restic\Admin\ListRecoveryRepositories;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Admin\RepositoryStatistics;
use IByte\Restic\Console\RepairInstallation;
use IByte\Restic\Storage\StorageReport;
use IByte\Restic\Support\Installation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

// Only the existing in-memory disposable SQLite fixture is mutated here.
check(DB::connection()->getDatabaseName() === ':memory:', 'missing-table reproduction uses disposable memory database');
$preserved = hash('sha256', json_encode(DB::table('backups')->get()));
$priorRepo = hash('sha256', json_encode(DB::table('ibyte_restic_repositories')->get()));
Schema::rename('ibyte_restic_repositories', 'ibyte_restic_repositories_fixture_saved');
check(Installation::missing() === ['ibyte_restic_repositories'], 'schema check detects missing recovery table even with logged migration');
$beforeMissingRequests = count(Http::recorded());
$unlockedMissing = $retentionServer->backups()->where('is_successful', true)->where('is_locked', false)->whereNotNull('completed_at')->firstOrFail();
rejected(fn () => app(DeleteBackupService::class)->handle($unlockedMissing), 'missing installation blocks delete before remote forget', 'installation is incomplete');
check(count(Http::recorded()) === $beforeMissingRequests && $preserved === hash('sha256', json_encode(DB::table('backups')->get())), 'missing table cannot cause node deletion followed by SQL failure');
check(str_contains(StorageReport::summary($retentionHost), 'Repair tables'), 'storage dialog reports incomplete installation without SQL error');
Filament::setCurrentPanel(Filament::getPanel('server'));
auth()->login($retentionServer->user);
Filament::setTenant($retentionServer);
// This successful fixture backup is locked; use a separate unlocked record for
// the native delete action so authorization reaches the install preflight.
$unlockedMissing = $retentionServer->backups()->where('is_successful', true)->where('is_locked', false)->whereNotNull('completed_at')->firstOrFail();
Livewire::test(ListBackups::class)->callTableAction('exclude_delete', $unlockedMissing)->assertStatus(200);
check($unlockedMissing->fresh() !== null && count(Http::recorded()) === $beforeMissingRequests, 'native delete action handles missing table with notification instead of generic HTTP 500');
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant(null, isQuiet: true);
auth()->login($formAdmin);
Livewire::test(RepositoryAdministration::class)->assertStatus(200)->assertSee('installation is incomplete');
Livewire::test(ListRecoveryRepositories::class)->assertStatus(200)->assertSee('Administrative');
Livewire::test(RepositoryStatistics::class)->assertStatus(200)->assertSee('Unavailable');
check(count(Http::recorded()) === $beforeMissingRequests, 'keys page renders repair controls without missing-table query or node calls');
auth()->login($stranger);
Livewire::test(RepositoryAdministration::class)->assertStatus(403);
check(Installation::missing() !== [], 'non-root cannot enter missing-schema repair page');
auth()->login($formAdmin);
Livewire::test(RepositoryAdministration::class)->callAction('ibyte-restic-repair-installation')->assertStatus(200);
check(Installation::missing() === [] && Schema::hasTable('ibyte_restic_repositories'), 'native password-free additive repair action recreates table even when migration was previously recorded');
check($preserved === hash('sha256', json_encode(DB::table('backups')->get())) && count(Http::recorded()) === $beforeMissingRequests, 'table repair preserves backups and never contacts or wipes nodes');
check(DB::table('ibyte_restic_repositories')->count() === 0, 'recreating missing recovery table does not invent historical encryption keys');
// Restore the saved disposable recovery data after checking the newly created
// table is empty; repair itself never drops or renames operator tables.
Schema::drop('ibyte_restic_repositories');
Schema::rename('ibyte_restic_repositories_fixture_saved', 'ibyte_restic_repositories');
// This fixture manually registers the plugin after Artisan already booted.
// The separate fresh-process suite proves automatic command registration.
app(Kernel::class)->registerCommand(app(RepairInstallation::class));
check(Artisan::call('restic:repair-installation') === 0 && $priorRepo === hash('sha256', json_encode(DB::table('ibyte_restic_repositories')->get())), 'CLI repair handler is repeat safe and preserves existing encrypted recovery rows');
check($preserved === hash('sha256', json_encode(DB::table('backups')->get())), 'repeated repair leaves every native backup unchanged');
Filament::setCurrentPanel(Filament::getPanel('server'));
auth()->login($retentionServer->user);
Filament::setTenant($retentionServer);
Livewire::test(ListBackups::class)->callTableAction('exclude_delete', $unlockedMissing)->assertStatus(200);
check($unlockedMissing->fresh() === null, 'after additive repair the actual native unlocked-backup delete action succeeds');
Filament::setTenant(null, isQuiet: true);
