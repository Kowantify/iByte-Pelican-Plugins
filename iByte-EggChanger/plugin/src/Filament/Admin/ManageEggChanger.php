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
use IByte\EggChanger\Models\Package;
use IByte\EggChanger\Models\PackageEgg;
use IByte\EggChanger\Packages\Evaluator;
use IByte\EggChanger\Support\Readiness;

/**
 * One admin entry: Packages / Operations / Diagnostics (AGENTS.md §5).
 * No file or SQL editing required for routine configuration.
 */
final class ManageEggChanger extends Page
{
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
                'failure_message' => (string) ($operation->failure_message ?? ''),
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
            Notification::make()->title('Reconcile failed')->body($exception->getMessage())->danger()->send();
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
            Notification::make()->title('Cancel failed')->body($exception->getMessage())->danger()->send();
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