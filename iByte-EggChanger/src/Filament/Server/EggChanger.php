<?php

namespace IByte\EggChanger\Filament\Server;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use IByte\EggChanger\Changes\SwitchService;
use IByte\EggChanger\Packages\Evaluator;
use IByte\EggChanger\Models\Recommendation;
use IByte\EggChanger\Models\Entitlement;
use Illuminate\Support\Facades\Schema;
use IByte\EggChanger\Support\Readiness;

/**
 * Client Egg Changer page (AGENTS.md §4).
 *
 * Owner-controlled apply is the default: generic file/startup/reinstall
 * subuser permissions never grant egg selection in v1 (§5 — delegated
 * switching is a documented future policy, disabled by default).
 */
final class EggChanger extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Switch2;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'egg-changer';

    protected string $view = 'ibyte-egg-changer::server';

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
        /** @var Server $server */
        $server = Filament::getTenant();

        // Owner-only in v1. Subusers with any permission set are excluded until
        // a delegated policy exists (never inferred from unrelated permissions).
        return user()?->id === $server->owner_id && Entitlement::allowed((int) $server->id);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** @return Server */
    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /** View-only game search. Does not bypass authorization or alter gates. */
    public string $eggSearch = '';
    public int $visibleEggLimit = 10;

    public function updatedEggSearch(): void
    {
        $this->visibleEggLimit = 10;
    }

    public function showMoreEggs(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->visibleEggLimit = min(1000, max(10, $this->visibleEggLimit) + 10);
    }

    /** @return array<string, mixed>|null null when the plugin is not ready (distinct state, never an empty egg list) */
    public function catalog(): ?array
    {
        $server = $this->getRecord();

        if (!Readiness::ready()) {
            return null;
        }

        $evaluator = new Evaluator();

        $allEggs = $evaluator->eligibleEggs($server);
        $totalEligible = count($allEggs);
        usort($allEggs, static function (array $a, array $b) use ($server): int {
            $aCurrent = (int) $a['egg_id'] === (int) $server->egg_id;
            $bCurrent = (int) $b['egg_id'] === (int) $server->egg_id;
            return $aCurrent === $bCurrent
                ? strcasecmp((string) $a['egg_name'], (string) $b['egg_name'])
                : ($aCurrent ? -1 : 1);
        });
        $search = trim(mb_substr($this->eggSearch, 0, 100));
        if ($search !== '') {
            $allEggs = array_values(array_filter($allEggs, static fn (array $egg): bool =>
                mb_stripos((string) $egg['egg_name'], $search) !== false
            ));
        }
        $matchingEggs = count($allEggs);
        $eggs = array_slice($allEggs, 0, min(1000, max(10, $this->visibleEggLimit)));
        if (Schema::hasTable('ibyte_egg_changer_recommendations')) {
            $profiles = Recommendation::query()->whereIn('egg_id', array_column($eggs, 'egg_id'))
                ->orderBy('sort_order')->orderBy('id')->get()->groupBy('egg_id');
            foreach ($eggs as &$egg) {
                $egg['use_cases'] = [];
                foreach ($profiles->get($egg['egg_id'], collect()) as $profile) {
                    $recommended = ((int) $server->memory === 0 || (int) $server->memory >= $profile->recommended_memory_mb)
                        && ((int) $server->disk === 0 || (int) $server->disk >= $profile->recommended_disk_mb);
                    $egg['use_cases'][] = [
                        'name' => $profile->name,
                        'description' => $profile->description,
                        'ram_gb' => $profile->recommended_memory_mb / 1024,
                        'disk_gb' => $profile->recommended_disk_mb / 1024,
                        'recommended' => $recommended,
                    ];
                }
            }
            unset($egg);
        }

        return [
            'ready' => true,
            'current_egg_id' => (int) $server->egg_id,
            'current_egg_name' => $server->egg?->name ?? 'Unknown',
            'memory_mi_b' => (int) $server->memory,
            'disk_mi_b' => (int) $server->disk,
            'memory_unlimited' => (int) $server->memory === 0,
            'disk_unlimited' => (int) $server->disk === 0,
            'eggs' => $eggs,
            'total_eligible' => $totalEligible,
            'matching_eggs' => $matchingEggs,
            'has_more_eggs' => $matchingEggs > count($eggs) && $this->visibleEggLimit < 1000,
            'operation' => SwitchService::latestFor($server),
        ];
    }

    /** Preview a target egg: configuration delta + destructive scope (server-side truth). */
    #[Locked]
    public ?array $preview = null;

    public function openPreview(int $eggId): void
    {
        abort_unless(self::canAccess(), 403);

        $this->preview = $this->preview($eggId);

        if (isset($this->preview['error'])) {
            Notification::make()->title($this->preview['error'])->danger()->send();
            $this->preview = null;
        }
    }

    /** Client can close, but not forge or replace, the server-generated preview. */
    public function cancelPreview(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->preview = null;
    }

    /** Server-side acknowledge gate: the preview must be open and the flag set. */
    public function confirmSwitch(int $eggId): void
    {
        abort_unless(self::canAccess(), 403);

        if ($this->preview === null || (int) ($this->preview['egg']['id'] ?? 0) !== $eggId) {
            Notification::make()->title('Preview mismatch - open the preview again before confirming')->danger()->send();

            return;
        }

        $this->queueSwitch($eggId);
        $this->preview = null;
    }

    /** Preview a target egg: configuration delta + destructive scope (server-side truth). */
    public function preview(int $eggId): array
    {
        $server = $this->getRecord();
        abort_unless(self::canAccess(), 403);

        $evaluator = new Evaluator();

        if ($eggId === (int) $server->egg_id) {
            return ['error' => 'This egg is already installed on this server.'];
        }

        if (!$evaluator->isEggEligible($server, $eggId)) {
            return ['error' => 'This egg is not currently eligible for this server.'];
        }

        $egg = \App\Models\Egg::query()->find($eggId);

        if (!$egg) {
            return ['error' => 'That egg no longer exists. Refresh and pick another.'];
        }

        $package = $evaluator->grantingPackage($server, $eggId);

        return [
            'egg' => ['id' => $egg->id, 'name' => $egg->name, 'description' => $egg->description],
            'package' => $package ? ['id' => $package->id, 'name' => $package->name, 'min_memory_mb' => $package->min_memory_mb, 'min_disk_mb' => $package->min_disk_mb] : null,
            'target_image' => collect((array) $egg->docker_images)->first() ?? '(egg default)',
            'target_startup_count' => is_countable($egg->startup_commands) ? count((array) $egg->startup_commands) : 0,
            'variables' => \App\Models\EggVariable::query()->where('egg_id', $egg->id)
                ->orderBy('id')
                ->get(['id', 'name', 'user_viewable', 'user_editable', 'rules'])
                ->map(fn ($v): array => [
                    'id' => $v->id, 'name' => $v->name,
                    'viewable' => (bool) $v->user_viewable, 'editable' => (bool) $v->user_editable,
                    'required' => $v->required,
                ])->all(),
            'deleted_scope' => 'All top-level files in this server\'s data directory',
            'backup_policy' => 'A verified native recovery backup is created before anything is deleted.',
            'data_note' => 'Changing the egg does not convert existing saves; this is a clean install for the new game.',
        ];
    }

    /**
     * Queue the switch after explicit client-side acknowledgement.
     * Gates are re-evaluated server-side here (preview may be stale).
     */
    private function queueSwitch(int $eggId): void
    {
        $server = $this->getRecord();
        abort_unless(self::canAccess(), 403);

        try {
            $operation = app(SwitchService::class)->queue($server, $eggId, (int) user()?->id);

            Notification::make()
                ->title('Egg change queued')
                ->body('Operation ' . substr((string) $operation->uuid, 0, 8) . ' is now running. Progress survives refresh; closing this page does not cancel it.')
                ->success()->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Egg change was not queued')
                ->body('An unexpected error prevented this action. Contact support; no secret diagnostics are shown.')
                ->danger()->send();
        }
    }
}