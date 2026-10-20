<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Filament\Admin;

use App\Models\Egg;
use App\Models\Server;
use BackedEnum;
use Filament\Pages\Page;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Software\ProfileWizard;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\SchemaInstaller;
use IByte\MinecraftManager\Support\Settings;
use IByte\MinecraftManager\Support\ViewCache;
use Livewire\Attributes\Locked;
use Throwable;

final class MinecraftManager extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-minecraft';
    protected static ?string $slug = 'minecraft-manager';
    protected string $view = 'ibyte-minecraft-manager::admin';
    #[Locked] public string $area = 'overview';
    #[Locked] public ?string $error = null;
    #[Locked] public ?string $message = null;
    #[Locked] public ?string $mappedEgg = null;
    public bool $enabled = false;
    public bool $experimental = false;
    public string $apiKey = '';
    public array $providers = ['mcjars', 'modrinth', 'curseforge'];
    public array $mapping = ['egg_id' => '', 'enabled' => true];

    public static function getNavigationLabel(): string { return trans('ibyte-minecraft-manager::messages.title'); }
    public function getTitle(): string { return self::getNavigationLabel(); }
    public static function canAccess(): bool { return auth()->user()?->isRootAdmin() ?? false; }
    private function guard(): void { abort_unless(self::canAccess(), 403); }

    public function mount(): void
    {
        $this->guard();
        $values = app(Settings::class)->values();
        $this->enabled = (bool) ($values['enabled'] ?? false);
        $this->experimental = (bool) ($values['experimental'] ?? false);
        $this->providers = $values['providers'] ?? ['mcjars', 'modrinth', 'curseforge'];
    }

    private function run(callable $callback): void
    {
        $this->guard();
        $this->error = $this->message = null;
        try { $callback(); $this->message = 'saved'; }
        catch (Failure $error) { $this->error = $error->reason; }
        catch (Throwable) { $this->error = 'request_failed'; }
    }

    public function switchArea(string $area): void
    {
        $this->guard();
        abort_unless(in_array($area, ['overview', 'settings', 'operations'], true), 422);
        $this->apiKey = '';
        $this->area = $area;
        $this->message = $this->error = null;
    }

    public function savePolicy(): void
    {
        $this->run(function (): void {
            $this->validate(['providers' => 'array|max:3', 'providers.*' => 'in:mcjars,modrinth,curseforge']);
            app(Settings::class)->save(['enabled' => $this->enabled, 'experimental' => $this->experimental, 'providers' => $this->providers]);
        });
    }

    public function replaceKey(): void
    {
        $this->guard();
        try { $this->run(fn () => app(Settings::class)->replaceCredential('curseforge', $this->apiKey)); }
        finally { $this->apiKey = ''; }
    }

    public function removeKey(): void { $this->run(fn () => app(Settings::class)->replaceCredential('curseforge', null)); }

    public function saveMapping(): void
    {
        $this->guard();
        $this->message = $this->error = $this->mappedEgg = null;
        $this->validate([
            'mapping.egg_id' => 'required|integer|exists:eggs,id',
            'mapping.enabled' => 'boolean',
        ]);
        $this->run(function (): void {
            $profile = app(ProfileWizard::class)->save($this->mapping)->fresh();
            $this->mappedEgg = $profile->name;
        });
    }

    public function toggleProfile(int $id): void
    {
        $this->run(function () use ($id): void {
            $profile = Profile::findOrFail($id);
            $profile->update(['enabled' => !$profile->enabled]);
        });
    }

    public function repairSchema(): void { $this->run(fn () => SchemaInstaller::install()); }

    public function reconcileReview(string $id): void
    {
        $this->run(function () use ($id): void {
            // Queued/preflight phases have not replaced live files.
            $updated = Operation::whereKey($id)->whereIn('status', ['queued', 'preflight'])
                ->where(function ($query): void { $query->where('selection->intent', 'review')->orWhereNull('selection->intent'); })
                ->where('updated_at', '<', now()->subMinutes(5))->update([
                    'status' => 'failed', 'step' => 'reconciled-read-only-review', 'error_code' => 'worker_interrupted',
                    'server_slot' => null, 'finished_at' => now(),
                ]);
            if (!$updated) { throw new Failure('reconciliation_not_available'); }
        });
    }

    protected function getViewData(): array
    {
        $this->guard();
        ViewCache::refresh(app('blade.compiler'));
        $settings = app(Settings::class);

        return [
            'installed' => $settings->installed(), 'keySet' => $settings->credential('curseforge') !== null,
            'eggs' => Egg::orderBy('name')->get(['id', 'name', 'docker_images']),
            'profiles' => $settings->installed() ? Profile::orderBy('name')->get() : collect(),
            'policyEnabled' => $settings->enabled(),
            'mappedServers' => $settings->installed() ? Server::whereIn('egg_id', Profile::where('enabled', true)->select('egg_id'))
                ->when($this->mapping['egg_id'] ?? null, fn ($query, $eggId) => $query->where('egg_id', $eggId))
                ->orderBy('name')->limit(50)->get(['id', 'uuid_short', 'name', 'egg_id']) : collect(),
        ];
    }
}
