<?php

use App\Services\Servers\ServerDeletionService;
use Filament\Facades\Filament;
use IByte\Restic\Admin\RepositoryAdministration;
use IByte\Restic\Console\DiagnoseRuntime;
use IByte\Restic\Integration\GuardedServerDeletion;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\RuntimeDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

$beforeData = hash('sha256', json_encode([DB::table('backups')->get(), DB::table('ibyte_restic_repositories')->get(), DB::table('ibyte_restic_recovery_audit')->get()]));
$beforeRequests = count(Http::recorded());
$snapshot = RuntimeDiagnostics::snapshot();
check($snapshot['ready'] && $snapshot['panel_source_matches'] && $snapshot['bindings'][ServerDeletionService::class]['actual'] === GuardedServerDeletion::class, 'runtime diagnostic identifies the actual installed guarded service and source contract');
check(str_ends_with(str_replace('\\', '/', $snapshot['diagnostic_file']), '/src/Support/RuntimeDiagnostics.php') && in_array('IByte\\Restic\\Providers\\ResticProvider', $snapshot['loaded_restic_providers'], true), 'diagnostic exposes actual code path and loaded provider without guessing from version string');
app()->bind(ServerDeletionService::class, ServerDeletionService::class);
try {
    $conflict = RuntimeDiagnostics::snapshot();
    check(! $conflict['ready'] && $conflict['bindings'][ServerDeletionService::class]['actual'] === ServerDeletionService::class, 'binding-conflict diagnostic names the actual native class without silently rebinding it');
    rejected(fn () => Compatibility::assertSupported(), 'normal execution remains blocked and explains the resolved class', 'Resolved '.ServerDeletionService::class);
    app(Kernel::class)->registerCommand(app(DiagnoseRuntime::class));
    check(Artisan::call('restic:diagnose') === 1 && json_decode(Artisan::output(), true)['bindings'][ServerDeletionService::class]['matches'] === false, 'read-only CLI emits machine-readable conflict and failing exit status');
    auth()->login($formAdmin);
    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $view = Livewire::test(RepositoryAdministration::class)->mountAction('panel-diagnostics')->assertStatus(200);
    check(json_decode($view->instance()->getMountedAction()->getModalDescription(), true)['ready'] === false, 'root Restic Backups diagnostics works even while the execution safety binding is missing');
    check(app(ServerDeletionService::class)::class === ServerDeletionService::class, 'viewing diagnostics does not replace the displaced service');
} finally {
    app()->bind(ServerDeletionService::class, GuardedServerDeletion::class);
}
check(Artisan::call('restic:diagnose') === 0 && json_decode(Artisan::output(), true)['ready'], 'CLI reports matching bindings after the test restores its own fixture binding');
$json = json_encode($snapshot);
check(! str_contains($json, 'password_base64') && ! str_contains($json, 'daemon_token') && ! str_contains($json, 'unique-disposable-server-password'), 'diagnostic output contains no repository passwords, node tokens or host configuration');
check($beforeRequests === count(Http::recorded()) && $beforeData === hash('sha256', json_encode([DB::table('backups')->get(), DB::table('ibyte_restic_repositories')->get(), DB::table('ibyte_restic_recovery_audit')->get()])), 'runtime diagnostics performs no Wings requests or backup/recovery/audit writes');
