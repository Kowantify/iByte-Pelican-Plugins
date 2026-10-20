<?php

use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\DependencyContract;
use IByte\Restic\Support\RuntimeDiagnostics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

$contractPath = base_path('composer.lock');
$contractOriginal = file_get_contents($contractPath);
$contractLock = json_decode($contractOriginal, true, flags: JSON_THROW_ON_ERROR);
$contractPins = json_decode(file_get_contents($root.'/panel-plugin/compatibility.json'), true, flags: JSON_THROW_ON_ERROR);
check(! isset($contractPins['panel_files']['composer.lock']) && count($contractPins['panel_runtime_packages']) > 100, 'whole lockfile digest is replaced by an explicit tested production package inventory');
try {
    $contractChanged = $contractLock;
    $contractChanged['content-hash'] = str_repeat('0', 32);
    $contractChanged['_readme'][] = 'Native plugin install metadata fixture';
    $contractChanged['packages-dev'] = [];
    $contractChanged['packages'][] = ['name' => 'fixture/additional-plugin-package', 'version' => '1.0.0', 'source' => ['url' => 'https://example.invalid/fixture.git', 'reference' => str_repeat('f', 40)]];
    file_put_contents($contractPath, json_encode($contractChanged, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    Compatibility::assertPanelSource();
    check(Compatibility::panelSource()['matches'], 'native plugin additions, lock metadata and dev package changes do not falsely disable unchanged tested runtime');
    $contractName = $contractChanged['packages'][0]['name'];
    $contractChanged['packages'][0]['version'] = '999.0.0';
    file_put_contents($contractPath, json_encode($contractChanged, JSON_THROW_ON_ERROR));
    $contractReport = RuntimeDiagnostics::snapshot();
    check(! $contractReport['ready'] && $contractReport['panel_contract']['file_mismatches'] === [] && $contractReport['panel_contract']['dependency_mismatches'][0]['package'] === $contractName, 'diagnostic identifies exact changed runtime package despite unchanged core files');
    check($contractReport['panel_contract']['dependency_mismatches'][0]['locked_version'] === '999.0.0' && $contractReport['panel_contract']['dependency_mismatches'][0]['installed_identity_matches'], 'diagnostics distinguish changed lock metadata from the actually installed package');
    check(Artisan::call('restic:diagnose') === 1 && json_decode(Artisan::output(), true)['panel_contract']['dependency_mismatches'][0]['package'] === $contractName, 'read-only CLI exits unsuccessfully and names the specific incompatible runtime package');
    try {
        Compatibility::assertPanelSource();
        throw new RuntimeException('Changed runtime package accepted');
    } catch (HttpException $exception) {
        check($exception->getStatusCode() === 409 && $exception->getHeaders()['Retry-After'] === 0 && str_contains($exception->getMessage(), 'Panel diagnostics'), 'source incompatibility is an actionable native HTTP conflict with a valid retry header');
    }
    auth()->login($actionServer->user);
    Filament::setCurrentPanel(Filament::getPanel('server'));
    Filament::setTenant($actionServer);
    $contractBefore = [$actionServer->backups()->count(), DB::table('ibyte_restic_operations')->count(), count(Http::recorded())];
    session()->forget('filament.notifications');
    Livewire::test(ListBackups::class)->callTableAction('create', data: ['name' => 'Must refuse incompatible dependencies', 'ignored' => '', 'is_locked' => false])->assertStatus(200);
    $contractNotifications = new Notifications;
    $contractNotifications->mount();
    $contractNotice = $contractNotifications->notifications->last()?->toArray();
    check(($contractNotice['status'] ?? '') === 'danger' && str_contains($contractNotice['body'] ?? '', 'Panel diagnostics'), 'actual native Create displays a useful notification instead of a Livewire HTTP 500');
    check($contractBefore === [$actionServer->backups()->count(), DB::table('ibyte_restic_operations')->count(), count(Http::recorded())], 'incompatible native creation changes no backups, durable operations or node traffic');
    $contractChanged = $contractLock;
    $contractChanged['packages'][0]['source']['reference'] = str_repeat('f', 40);
    file_put_contents($contractPath, json_encode($contractChanged, JSON_THROW_ON_ERROR));
    check(! Compatibility::panelSource()['matches'], 'same-version runtime source reference drift remains rejected');
    $contractChanged['packages'][0]['source']['url'] = 'https://private-user:must-not-leak@example.invalid/fixture.git';
    file_put_contents($contractPath, json_encode($contractChanged, JSON_THROW_ON_ERROR));
    check(! str_contains(json_encode(RuntimeDiagnostics::snapshot()), 'must-not-leak'), 'diagnostics never echo credentials from a changed dependency source URL');
    $contractChanged = $contractLock;
    array_shift($contractChanged['packages']);
    file_put_contents($contractPath, json_encode($contractChanged, JSON_THROW_ON_ERROR));
    check(! Compatibility::panelSource()['matches'], 'missing pinned runtime package remains rejected even when installed vendor code exists');
    file_put_contents($contractPath, '{invalid-json');
    check(! Compatibility::panelSource()['matches'], 'unreadable runtime package inventory fails closed without trusting the version label');
} finally {
    file_put_contents($contractPath, $contractOriginal);
    auth()->login($formAdmin);
    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}
check(hash_file('sha256', $contractPath) === hash('sha256', $contractOriginal) && Compatibility::panelSource()['matches'], 'source-drift fixture restores exact upstream lock bytes and readiness');
$contractExpected = ['fixture/runtime' => ['version' => '1.0.0', 'source_reference' => str_repeat('a', 40), 'dist_reference' => str_repeat('a', 40), 'source_url' => 'https://example.invalid/runtime.git']];
$contractPackages = [['name' => 'fixture/runtime', 'version' => '1.0.0', 'source' => ['reference' => str_repeat('a', 40), 'url' => 'https://example.invalid/runtime.git'], 'dist' => ['reference' => str_repeat('a', 40)]]];
check(DependencyContract::compare($contractExpected, $contractPackages, []) !== [], 'a package in the lock but absent from Composer installed metadata is rejected');
check(DependencyContract::compare($contractExpected, $contractPackages, ['fixture/runtime' => ['version' => '2.0.0', 'reference' => str_repeat('a', 40)]]) !== [], 'installed runtime version drift cannot hide behind the original lock');
check(DependencyContract::compare($contractExpected, $contractPackages, ['fixture/runtime' => ['version' => '1.0.0', 'reference' => str_repeat('b', 40)]]) !== [], 'installed runtime reference drift remains rejected');
check(DependencyContract::compare($contractExpected, [...$contractPackages, ...$contractPackages], []) !== [], 'duplicate lock package entries cannot shadow the pinned identity');
