<?php

namespace IByte\EggChanger\Filament\Admin;

use App\Enums\TablerIcon;
use App\Models\Egg;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use IByte\EggChanger\Changes\Reservation;
use IByte\EggChanger\Models\Operation;
use IByte\EggChanger\Models\Entitlement;
use IByte\EggChanger\Models\Package;
use IByte\EggChanger\Models\PackageEgg;
use IByte\EggChanger\Models\Recommendation;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Log;
use Livewire\WithPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use IByte\EggChanger\Packages\Evaluator;
use IByte\EggChanger\Support\Readiness;

/**
 * One admin entry: Packages / Operations / Diagnostics (AGENTS.md §5).
 * No file or SQL editing required for routine configuration.
 */
final class ManageEggChanger extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Switch2;

    protected static ?string $slug = 'egg-changer';

    protected string $view = 'ibyte-egg-changer::admin';

    public static function getNavigationLabel(): string
    {
        return 'Egg Changer';
    }

    public function getTitle(): string
    {
        return 'Egg Changer';
    }

    public static function canAccess(): bool
    {
        return user()?->isRootAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->packageForm = $this->defaultPackageForm();
        $this->recommendationForm = $this->defaultRecommendationForm();
    }

    /** @return array<string, mixed> */
    private function defaultPackageForm(): array
    {
        return [
            'name' => '',
            'description' => '',
            'min_memory_mb' => 0,
            'min_disk_mb' => 0,
            'sort_order' => 0,
            'enabled' => false,
            'egg_ids' => [],
        ];
    }


    /** Search the local Pelican server registry. No Paymenter API is queried. */
    // Display-only navigation. None of these fields modifies entitlements or gates.
    public string $activeAdminTab = 'servers';
    public bool $showPackageForm = false;
    public bool $showRecommendationForm = false;
    public string $recommendationFilterEggId = '';

    public function showAdminTab(string $tab): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless(in_array($tab, ['servers', 'packages', 'recommendations', 'operations', 'diagnostics'], true), 422);
        $this->activeAdminTab = $tab;
    }

    public function openPackageForm(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->editingPackageId = null;
        $this->packageForm = $this->defaultPackageForm();
        $this->showPackageForm = true;
    }

    public function openRecommendationForm(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->editingRecommendationId = null;
        $this->recommendationForm = $this->defaultRecommendationForm();
        if (ctype_digit($this->recommendationFilterEggId) && (int) $this->recommendationFilterEggId > 0) {
            $this->recommendationForm['egg_id'] = $this->recommendationFilterEggId;
        }
        $this->showRecommendationForm = true;
    }

    public string $serverSearch = '';

    /** Include suspended, installing and failed-install servers when needed. */
    public bool $includeInactiveServers = false;

    public function updatedServerSearch(): void
    {
        $this->resetPage('switching-servers');
    }

    public function updatedIncludeInactiveServers(): void
    {
        $this->resetPage('switching-servers');
    }

    /**
     * Bound the result set: large hosting fleets must never render all servers.
     * In Pelican a normally installed, non-suspended server has NULL status.
     */
    public function getSwitchableServers(): LengthAwarePaginator
    {
        abort_unless(self::canAccess(), 403);

        $query = Server::query()->with('user');
        if (!$this->includeInactiveServers) {
            $query->whereNull('status');
        }

        $search = trim($this->serverSearch);
        if ($search !== '') {
            $search = mb_substr($search, 0, 100);
            // Escape LIKE wildcards so user-entered '_' or '%' is literal.
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($term, $search): void {
                $q->where('name', 'like', $term)
                    ->orWhere('uuid', 'like', $term)
                    ->orWhereHas('user', function ($owner) use ($term): void {
                        $owner->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        return $query->orderBy('name')->orderBy('id')
            ->paginate(12, ['*'], 'switching-servers');
    }

    /** Read at most the entitlements on this page, without an N+1 query. */
    public function getSwitchEntitlementStates(LengthAwarePaginator $servers): array
    {
        abort_unless(self::canAccess(), 403);
        if (!DbSchema::hasTable('ibyte_egg_changer_entitlements')) {
            return [];
        }
        $items = collect($servers->items());
        if ($items->isEmpty()) {
            return [];
        }
        $records = Entitlement::query()->whereIn('server_id', $items->pluck('id'))
            ->get()->keyBy('server_id');
        $states = [];
        foreach ($items as $server) {
            $row = $records->get($server->id);
            $states[$server->id] = $row !== null
                && (string) $row->server_uuid === (string) $server->uuid
                && (bool) $row->enabled;
        }
        return $states;
    }

    /** Each action re-authorizes and resolves the server on the backend. */
    public function setSwitchEntitlementForServer(int $serverId, bool $enabled): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless($serverId > 0, 422);
        abort_unless(DbSchema::hasTable('ibyte_egg_changer_entitlements'), 503);

        $server = Server::query()->findOrFail($serverId);
        $existing = Entitlement::query()->where('server_id', $server->id)->first();
        // Fail closed rather than silently granting an entitlement to a reused ID.
        if ($existing && (string) $existing->server_uuid !== (string) $server->uuid) {
            Notification::make()->title('Server identity mismatch; investigate the old entitlement record')
                ->danger()->send();
            return;
        }
        Entitlement::query()->updateOrCreate(
            ['server_id' => $server->id],
            ['server_uuid' => (string) $server->uuid, 'enabled' => $enabled,
             'source' => 'admin', 'external_service_id' => null,
             'updated_by' => (int) user()->id]
        );
        // Audit metadata only: no UUID, customer email, token or request payload.
        Log::info('ibyte-egg-changer: administrator changed switching access', [
            'server_id' => $server->id, 'admin_id' => (int) user()->id, 'enabled' => $enabled,
        ]);
        Notification::make()->title('Game switching ' . ($enabled ? 'enabled' : 'disabled')
            . ' for ' . $server->name)->success()->send();
    }

    /* ======================================================================
     * Packages
     * ==================================================================== */

    public ?array $packageForm = [];

    /** When set, the form edits this existing package instead of creating one. */
    public ?int $editingPackageId = null;

    /** @return array<int, array<string, mixed>> */
    public function getPackages(): array
    {
        if (!Readiness::ready()) {
            return [];
        }

        return Package::query()->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(function (Package $package): array {
                $eggIds = PackageEgg::query()->where('package_id', $package->id)
                    ->orderBy('egg_id')->pluck('egg_id')->all();

                $eggs = Egg::query()->whereIn('id', $eggIds)->orderBy('name')->get();

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => (string) ($package->description ?? ''),
                    'min_memory_mb' => $package->min_memory_mb,
                    'min_disk_mb' => $package->min_disk_mb,
                    'sort_order' => $package->sort_order,
                    'enabled' => $package->enabled,
                    'eggs' => $eggs->map(fn (Egg $egg) => ['id' => $egg->id, 'name' => $egg->name])->all(),
                ];
            })
            ->all();
    }

    /**
     * Form submit router: create when browsing, update when editing.
     */
    public function submitPackage(): void
    {
        if ($this->editingPackageId) {
            $this->savePackage();

            return;
        }

        $this->createPackage();
    }

    /** Load an existing package into the edit form. */
    public function editPackage(int $packageId): void
    {
        abort_unless(self::canAccess(), 403);

        $package = Package::query()->find($packageId);

        if (!$package) {
            Notification::make()->title('Package not found')->danger()->send();

            return;
        }

        $this->showPackageForm = true;
        $this->editingPackageId = $package->id;
        $this->packageForm = [
            'name' => $package->name,
            'description' => (string) ($package->description ?? ''),
            'min_memory_mb' => $package->min_memory_mb,
            'min_disk_mb' => $package->min_disk_mb,
            'sort_order' => $package->sort_order,
            'enabled' => $package->enabled,
            'egg_ids' => PackageEgg::query()->where('package_id', $package->id)
                ->orderBy('egg_id')->pluck('egg_id')->all(),
        ];
    }

    /** Discard in-progress edits and return the form to create mode. */
    public function cancelEdit(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->editingPackageId = null;
        $this->packageForm = $this->defaultPackageForm();
        $this->showPackageForm = false;
    }

    /**
     * Normalize and validate the package form.
     * Returns normalized fields, or null after sending an error notification.
     *
     * @return array{name: string, description: ?string, egg_ids: int[], min_memory_mb: int, min_disk_mb: int, sort_order: int, enabled: bool}|null
     */
    private function parsePackageForm(): ?array
    {
        $data = $this->packageForm ?? $this->defaultPackageForm();

        $name = trim((string) ($data['name'] ?? ''));

        // Multi-select arrives as an array of string ids (Livewire serializes
        // option values as strings); normalize strictly and deduplicate.
        $eggIds = [];
        foreach ((array) ($data['egg_ids'] ?? []) as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $eggIds[(int) $value] = (int) $value; // dedupe
            }
        }
        $eggIds = array_values($eggIds);

        // Keep only real, currently-existing eggs (a stale browser state or an
        // old mapping can reference eggs that were deleted since).
        if ($eggIds !== []) {
            $existing = Egg::query()->whereIn('id', $eggIds)->pluck('id')->all();
            $eggIds = array_values(array_intersect($eggIds, $existing));
        }

        $minMemory = (int) ($data['min_memory_mb'] ?? 0);
        $minDisk = (int) ($data['min_disk_mb'] ?? 0);
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($name === '') {
            Notification::make()->title('Package name is required')->danger()->send();

            return null;
        }

        if ($minMemory < 0 || $minDisk < 0) {
            Notification::make()->title('Minimums must be zero or positive MiB values')->danger()->send();

            return null;
        }

        if ($eggIds === []) {
            Notification::make()->title('Select at least one approved egg that exists')->danger()->send();

            return null;
        }

        return [
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'egg_ids' => $eggIds,
            'min_memory_mb' => $minMemory,
            'min_disk_mb' => $minDisk,
            'sort_order' => $sortOrder,
            'enabled' => $enabled,
        ];
    }

    public function createPackage(): void
    {
        abort_unless(self::canAccess(), 403);
        Readiness::assertReady();

        $fields = $this->parsePackageForm();

        if ($fields === null) {
            return;
        }

        $package = Package::create([
            'name' => $fields['name'],
            'description' => $fields['description'],
            'min_memory_mb' => $fields['min_memory_mb'],
            'min_disk_mb' => $fields['min_disk_mb'],
            'sort_order' => $fields['sort_order'],
            'enabled' => $fields['enabled'],
        ]);

        foreach ($fields['egg_ids'] as $egg) {
            PackageEgg::create(['package_id' => $package->id, 'egg_id' => $egg]);
        }

        $this->packageForm = $this->defaultPackageForm();
        $this->showPackageForm = false;

        Notification::make()->title('Package created')->success()->send();
    }

    /** Update an existing package (fields + egg mappings replaced). */
    public function savePackage(): void
    {
        abort_unless(self::canAccess(), 403);
        Readiness::assertReady();

        $package = $this->editingPackageId ? Package::query()->find($this->editingPackageId) : null;

        if (!$package) {
            Notification::make()->title('The package being edited no longer exists')->danger()->send();
            $this->cancelEdit();

            return;
        }

        $fields = $this->parsePackageForm();

        if ($fields === null) {
            return;
        }

        $package->fill([
            'name' => $fields['name'],
            'description' => $fields['description'],
            'min_memory_mb' => $fields['min_memory_mb'],
            'min_disk_mb' => $fields['min_disk_mb'],
            'sort_order' => $fields['sort_order'],
            'enabled' => $fields['enabled'],
        ])->save();

        // Replace the egg mappings with the edited selection. Policy mapping
        // only: the package id, servers, eggs and operation history never change.
        PackageEgg::query()->where('package_id', $package->id)->delete();

        foreach ($fields['egg_ids'] as $egg) {
            PackageEgg::create(['package_id' => $package->id, 'egg_id' => $egg]);
        }

        $this->editingPackageId = null;
        $this->packageForm = $this->defaultPackageForm();
        $this->showPackageForm = false;

        Notification::make()->title('Package updated (egg mappings replaced; servers and history untouched)')->success()->send();
    }

    public function togglePackage(int $packageId): void
    {
        abort_unless(self::canAccess(), 403);
        $package = Package::query()->find($packageId);

        if (!$package) {
            return;
        }

        $package->enabled = !$package->enabled;
        $package->save();

        Notification::make()->title($package->enabled ? 'Package enabled' : 'Package disabled')->success()->send();
    }

    public function deletePackage(int $packageId): void
    {
        abort_unless(self::canAccess(), 403);
        $package = Package::query()->find($packageId);

        if (!$package) {
            return;
        }

        // Mapping-only deletion: packages/eggs/mappings go; servers, eggs and
        // operation history never do (AGENTS.md §5, §9).
        $package->delete();

        // If the deleted package was being edited, drop the edit state.
        if ($this->editingPackageId === $packageId) {
            $this->cancelEdit();
        }

        Notification::make()->title('Package deleted (policy mapping only; servers and eggs untouched)')->success()->send();
    }


    /* ======================================================================
     * Optional use-case guidance. NEVER changes actual switching permissions.
     * ==================================================================== */

    public array $recommendationForm = [];
    public ?int $editingRecommendationId = null;

    private function defaultRecommendationForm(): array
    {
        return [
            'egg_id' => '', 'name' => '', 'description' => '',
            'memory_gb' => 4, 'disk_gb' => 20, 'sort_order' => 0,
        ];
    }

    public function recommendationsReady(): bool
    {
        return DbSchema::hasTable('ibyte_egg_changer_recommendations');
    }

    public function getRecommendations(): array
    {
        if (!$this->recommendationsReady()) {
            return [];
        }
        $query = Recommendation::query();
        if (ctype_digit($this->recommendationFilterEggId) && (int) $this->recommendationFilterEggId > 0) {
            $query->where('egg_id', (int) $this->recommendationFilterEggId);
        }
        // One screen should not render hundreds of profiles. Select a game to filter.
        $profiles = $query->orderBy('egg_id')->orderBy('sort_order')->orderBy('id')->limit(50)->get();
        $names = Egg::query()->whereIn('id', $profiles->pluck('egg_id')->unique()->all())
            ->pluck('name', 'id');
        return $profiles->map(fn (Recommendation $r) => [
            'id' => $r->id,
            'egg_id' => $r->egg_id,
            'egg_name' => $names[$r->egg_id] ?? 'Deleted egg',
            'name' => $r->name,
            'description' => $r->description,
            'memory_gb' => $r->recommended_memory_mb / 1024,
            'disk_gb' => $r->recommended_disk_mb / 1024,
        ])->all();
    }

    public function editRecommendation(int $id): void
    {
        abort_unless(self::canAccess(), 403);
        $profile = Recommendation::query()->findOrFail($id);
        $this->showRecommendationForm = true;
        $this->editingRecommendationId = $profile->id;
        $this->recommendationForm = [
            'egg_id' => (string) $profile->egg_id,
            'name' => $profile->name,
            'description' => $profile->description ?? '',
            'memory_gb' => $profile->recommended_memory_mb / 1024,
            'disk_gb' => $profile->recommended_disk_mb / 1024,
            'sort_order' => $profile->sort_order,
        ];
    }

    public function cancelRecommendationEdit(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->editingRecommendationId = null;
        $this->recommendationForm = $this->defaultRecommendationForm();
        $this->showRecommendationForm = false;
    }

    public function saveRecommendation(): void
    {
        abort_unless(self::canAccess(), 403);
        if (!$this->recommendationsReady()) {
            Notification::make()->title('Install the new recommendation migration first')->danger()->send();
            return;
        }
        $data = Validator::make($this->recommendationForm, [
            'egg_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:90'],
            'description' => ['nullable', 'string', 'max:400'],
            'memory_gb' => ['required', 'numeric', 'min:0.5', 'max:1024'],
            'disk_gb' => ['required', 'numeric', 'min:0.5', 'max:10240'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99999'],
        ])->validate();

        // Recommendations are only useful for games an administrator has
        // already included in a package; never add new gate entitlements here.
        $eggId = (int) $data['egg_id'];
        if (!Egg::query()->whereKey($eggId)->exists()
            || !PackageEgg::query()->where('egg_id', $eggId)->exists()) {
            Notification::make()->title('Choose an egg already mapped to an Egg Changer package')->danger()->send();
            return;
        }
        $memoryMb = (int) round((float) $data['memory_gb'] * 1024);
        $diskMb = (int) round((float) $data['disk_gb'] * 1024);
        $attributes = [
            'egg_id' => $eggId,
            'name' => trim($data['name']),
            'description' => trim($data['description'] ?? '') ?: null,
            'recommended_memory_mb' => $memoryMb,
            'recommended_disk_mb' => $diskMb,
            'sort_order' => (int) $data['sort_order'],
        ];
        if ($this->editingRecommendationId) {
            Recommendation::query()->findOrFail($this->editingRecommendationId)->update($attributes);
        } else {
            Recommendation::query()->create($attributes);
        }
        $this->cancelRecommendationEdit();
        Notification::make()->title('Use-case recommendation saved')->success()->send();
    }

    public function deleteRecommendation(int $id): void
    {
        abort_unless(self::canAccess(), 403);
        Recommendation::query()->whereKey($id)->delete();
        if ($this->editingRecommendationId === $id) {
            $this->cancelRecommendationEdit();
        }
        Notification::make()->title('Use case removed; package gates unchanged')->success()->send();
    }

    /* ======================================================================
     * Eligibility diagnostics (admin-only, hypothetical inputs)
     * ==================================================================== */

    public ?int $previewServerId = null;

    public ?int $previewMemoryMb = null;

    public ?int $previewDiskMb = null;

    /** @return array<string, mixed>|null */
    public function previewEligibility(): ?array
    {
        abort_unless(self::canAccess(), 403);

        $server = $this->previewServerId ? Server::query()->find($this->previewServerId) : null;

        if (!$server) {
            Notification::make()->title('Select a real server to preview')->danger()->send();

            return null;
        }

        $memory = (int) ($this->previewMemoryMb ?? $server->memory);
        $disk = (int) ($this->previewDiskMb ?? $server->disk);

        $evaluator = new Evaluator();
        $eligible = $evaluator->eligibleEggs($server);

        // Re-evaluate against the hypothetical numbers (admin diagnostic only);
        // these results never create an entitlement.
        $hypothetical = [];
        foreach (Package::query()->where('enabled', true)->orderBy('sort_order')->get() as $package) {
            $qualifiesByMin = ($package->min_memory_mb <= 0 || $memory >= $package->min_memory_mb)
                && ($package->min_disk_mb <= 0 || $disk >= $package->min_disk_mb);

            $hypothetical[] = [
                'package_id' => $package->id,
                'name' => $package->name,
                'hypothetical_qualified' => $qualifiesByMin,
            ];
        }

        return [
            'server' => ['id' => $server->id, 'name' => $server->name, 'uuid' => (string) $server->uuid],
            'actual_memory_mb' => (int) $server->memory,
            'actual_disk_mb' => (int) $server->disk,
            'hypothetical_memory_mb' => $memory,
            'hypothetical_disk_mb' => $disk,
            'eligible_eggs' => $eligible,
            'hypothetical' => $hypothetical,
        ];
    }

    /* ======================================================================
     * Operations
     * ==================================================================== */

    /** @return array<int, array<string, mixed>> */
    public function getOperations(): array
    {
        if (!Readiness::ready()) {
            return [];
        }

        return Operation::query()->orderByDesc('id')->limit(50)
            ->get()
            ->map(fn (Operation $operation): array => [
                'uuid' => $operation->uuid,
                'server_uuid' => $operation->server_uuid,
                'server_id' => $operation->server_id,
                'source_egg_id' => $operation->source_egg_id,
                'target_egg_id' => $operation->target_egg_id,
                'package_id' => $operation->package_id,
                'snapshot' => sprintf('%d MiB RAM / %d MiB disk', $operation->snapshot_memory_mb, $operation->snapshot_disk_mb),
                'phase' => $operation->phase,
                'failure_stage' => (string) ($operation->failure_stage ?? ''),
                // Historical journal rows can contain raw remote error text.
                'failure_message' => $operation->failure_message === null ? '' : 'Inspect failure stage and operation reference; raw diagnostics are not displayed.', 
                'backup_uuid' => (string) ($operation->backup_uuid ?? ''),
                'backup_successful' => $operation->backup_successful,
                'queued_at' => $operation->queued_at?->format('Y-m-d H:i:s'),
                'finished_at' => $operation->finished_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    public function reconcileOperation(string $uuid, bool $completed): void
    {
        abort_unless(self::canAccess(), 403);

        $operation = Operation::query()->where('uuid', $uuid)->first();

        if (!$operation) {
            Notification::make()->title('Operation not found')->danger()->send();

            return;
        }

        try {
            \IByte\EggChanger\Changes\SwitchService::reconcile($operation, $completed);
            Notification::make()->title('Operation reconciled: ' . $operation->phase)->success()->send();
        } catch (\Throwable $exception) {
            Notification::make()->title('Reconcile failed')->body('Check the operation status and server logs securely.')->danger()->send();
        }
    }

    public function cancelOperation(string $uuid): void
    {
        abort_unless(self::canAccess(), 403);

        $operation = Operation::query()->where('uuid', $uuid)->first();

        if (!$operation) {
            Notification::make()->title('Operation not found')->danger()->send();

            return;
        }

        try {
            \IByte\EggChanger\Changes\SwitchService::cancelQueued($operation);
            Notification::make()->title('Queued operation cancelled')->success()->send();
        } catch (\Throwable $exception) {
            Notification::make()->title('Cancel failed')->body('Check the operation status and server logs securely.')->danger()->send();
        }
    }

    /* ======================================================================
     * Diagnostics
     * ==================================================================== */

    /** @return array<string, mixed> */
    public function getDiagnostics(): array
    {
        return [
            'status' => Readiness::status(),
            'error' => Readiness::error(),
            'missing_tables' => Readiness::missing(),
            'contracts' => Readiness::contracts(),
            'ready' => Readiness::ready(),
        ];
    }

    /** Releases any stuck per-server lock (admin recovery lever). */
    public function releaseReservation(int $serverId): void
    {
        abort_unless(self::canAccess(), 403);
        Reservation::release($serverId);
        Notification::make()->title("Released per-server reservation lock for server #{$serverId}")->success()->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }
}