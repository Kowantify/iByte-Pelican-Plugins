<?php

// Runs inside scripts/test-panel.php's disposable upstream/SQLite application.
use App\Enums\PluginStatus;
use App\Filament\Admin\Resources\BackupHosts\Pages\CreateBackupHost;
use App\Filament\Admin\Resources\BackupHosts\Pages\EditBackupHost;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use IByte\Restic\ResticSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

Filament::setCurrentPanel(Filament::getPanel('admin'));
$formAdmin = User::factory()->create();
$formAdmin->assignRole(Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']));
Auth::login($formAdmin);

$create = Livewire::test(CreateBackupHost::class)
    ->set('data.name', 'Form defaults regression')
    ->set('data.schema', 'restic');
check($create->get('data.configuration') === ResticSchema::DEFAULT_CONFIGURATION, 'native schema change initializes every Restic path before Alpine entanglement');
$create->call('create');
$formHost = BackupHost::where('name', 'Form defaults regression')->firstOrFail();
check($create->instance()->getErrorBag()->isEmpty() && $formHost->configuration === ResticSchema::DEFAULT_CONFIGURATION, 'actual native create form validates and persists local/profile defaults with both opt-ins off');

$edit = Livewire::test(EditBackupHost::class, ['record' => $formHost->id])
    ->set('data.configuration.backend', 's3')
    ->set('data.configuration.profile', 'prepared-s3')
    ->set('data.configuration.staging_enabled', true)
    ->set('data.configuration.restore_fixture_enabled', true)
    ->call('save');
$expectedEdited = ['backend' => 's3', 'profile' => 'prepared-s3', 'staging_enabled' => false, 'restore_fixture_enabled' => false];
check($edit->instance()->getErrorBag()->isEmpty() && $formHost->fresh()->configuration === $expectedEdited, 'native edit persists backend/profile while ignoring tampered hidden safety flags');
$reopened = Livewire::test(EditBackupHost::class, ['record' => $formHost->id]);
check($reopened->get('data.configuration') === $expectedEdited, 'opening an existing host preserves saved configuration instead of applying create defaults');

$switch = Livewire::test(CreateBackupHost::class)->set('data.schema', 's3');
check(! array_key_exists('backend', $switch->get('data.configuration') ?? []), 'native S3 schema remains untouched by Restic initialization');
$switch->set('data.configuration.secret', 'fixture')->set('data.schema', 'restic');
check($switch->get('data.configuration') === ResticSchema::DEFAULT_CONFIGURATION, 'switching into Restic discards unrelated provider configuration and credentials');
$switch->set('data.schema', 'wings');
check(! str_contains($switch->html(), 'Node credential profile reference'), 'Restic-only controls disappear when switching back to Wings');

foreach ([
    ['backend', null, 'required'],
    ['backend', 'ftp', 'in'],
    ['profile', '', 'required'],
    ['profile', '../invalid', 'regex'],
] as [$field, $value, $rule]) {
    $invalid = Livewire::test(CreateBackupHost::class)
        ->set('data.name', 'Invalid form '.$field.' '.$rule)
        ->set('data.schema', 'restic')
        ->set('data.configuration.'.$field, $value)
        ->call('create');
    check($invalid->instance()->getErrorBag()->has('data.configuration.'.$field)
        && ! BackupHost::where('name', 'Invalid form '.$field.' '.$rule)->exists(), 'native form rejects '.$field.' '.$rule.' without creating a host');
}

Auth::logout();

$beforeUpdate = [BackupHost::count(), Backup::count(), DB::table('ibyte_restic_operations')->count()];
$plugins->downloadPluginFromFile(new UploadedFile($root.'/release/Panel Plugin/'.$panelZipName, 'replacement.zip', 'application/zip', null, true));
Plugin::refreshRows();
check(Plugin::findOrFail('ibyte-restic')->status === PluginStatus::Enabled, 'native re-import preserves installed/enabled plugin metadata');
check(hash_file('sha256', plugin_path('ibyte-restic', 'src', 'Admin', 'HostForm.php')) === hash_file('sha256', $root.'/panel-plugin/src/Admin/HostForm.php')
    && hash_file('sha256', plugin_path('ibyte-restic', 'src', 'Nodes', 'Transport.php')) === hash_file('sha256', $root.'/panel-plugin/src/Nodes/Transport.php'), 'replacement ZIP installs the actual corrected form and transport code');
$plugins->installPlugin(Plugin::findOrFail('ibyte-restic'));
check($beforeUpdate === [BackupHost::count(), Backup::count(), DB::table('ibyte_restic_operations')->count()], 'native re-import/reinstall preserves hosts, backup records and operation history');
