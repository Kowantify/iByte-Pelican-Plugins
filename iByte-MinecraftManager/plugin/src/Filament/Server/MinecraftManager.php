<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Filament\Server;

use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Packages\Inventory;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Compatibility;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\Settings;
use IByte\MinecraftManager\Support\ViewCache;
use Livewire\Attributes\Locked;
use Throwable;

final class MinecraftManager extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-minecraft';

    protected static ?string $slug = 'minecraft-manager';

    protected static ?int $navigationSort = 98;

    protected string $view = 'ibyte-minecraft-manager::server';

    #[Locked]
    public string $area = 'overview';

    #[Locked]
    public array $results = [];

    #[Locked]
    public array $types = [];

    #[Locked]
    public array $versions = [];

    #[Locked]
    public array $builds = [];

    #[Locked]
    public array $releases = [];

    #[Locked]
    public array $inventory = [];

    #[Locked]
    public string $project = '';

    #[Locked]
    public string $software = '';

    #[Locked]
    public string $softwareVersion = '';

    #[Locked]
    public ?string $error = null;

    public string $provider = 'modrinth';

    public string $query = '';

    public string $sort = 'popular';

    public string $gameVersion = '';

    public string $loaderFilter = '';

    public string $packageType = 'mod';

    public string $installVersion = '';

    public string $installLoader = '';

    #[Locked]
    public string $releaseVersion = '';

    #[Locked]
    public string $releaseLoader = '';

    public string $selectedSoftware = '';

    public string $selectedSoftwareVersion = '';

    public string $selectedBuild = '';

    public string $selectedRelease = '';

    public string $softwareVersionQuery = '';

    public string $softwareBuildQuery = '';

    #[Locked]
    public bool $projectOpen = false;

    #[Locked]
    public array $installQueue = [];

    #[Locked]
    public array $pendingInstall = [];

    public bool $experimental = false;

    public int $page = 1;

    public int $releasePage = 1;

    #[Locked]
    public bool $releaseHasMore = false;

    #[Locked]
    public int $buildPage = 1;

    #[Locked]
    public int $total = 0;

    #[Locked]
    public array $packageFilterStates = [];

    #[Locked]
    public string $filterPackageType = 'mod';

    #[Locked]
    public string $filterRuntimeKey = '';

    public static function getNavigationLabel(): string
    {
        return trans('ibyte-minecraft-manager::messages.title');
    }

    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = parent::getNavigationSort() ?? 98;
        $panel = Filament::getCurrentPanel();
        if (!$panel || $panel->getId() !== 'server') {
            return $sort;
        }
        // Subdomains can be a third-party page, resource or explicit navigation
        // item. Follow its registered position without changing its plugin.
        foreach (array_unique([...$panel->getPages(), ...$panel->getResources()]) as $page) {
            if ($page !== self::class && str_contains(strtolower(class_basename($page)), 'subdomain')) {
                $sort = max($sort, ($page::getNavigationSort() ?? 0) + 1);
            }
        }
        foreach ($panel->getNavigationItems() as $item) {
            if (preg_match('/sub\s?domains/i', $item->getLabel())) {
                $sort = max($sort, $item->getSort() + 1);
            }
        }

        return $sort;
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Server && app(Access::class)->allowed(auth()->user(), $tenant);
    }

    public function mount(): void
    {
        $server = $this->guard();
        $this->installQueue = $this->savedQueue($server);
        $sources = array_values(array_filter(['modrinth', 'curseforge'], fn ($p) => app(Settings::class)->providerEnabled($p)));
        $this->provider = $sources[0] ?? 'modrinth';
    }

    private function guard(string $action = 'browse'): Server
    {
        $server = Filament::getTenant();
        abort_unless($server instanceof Server, 403);
        app(Access::class)->authorize(auth()->user(), $server, $action);

        return $server;
    }

    private function run(callable $work): void
    {
        $this->guard();
        $this->error = null;
        try {
            $work();
        } catch (Failure $failure) {
            $this->error = $failure->reason;
        } catch (Throwable) {
            $this->error = 'request_failed';
        }
    }

    public function switchArea(string $area): void
    {
        $this->guard();
        abort_unless(in_array($area, ['overview', 'software', 'packages', 'packs'], true), 422);
        $this->rememberPackageFilters();
        $this->area = $area;
        $this->results = $this->releases = [];
        $this->project = '';
        $this->page = $this->releasePage = 1;
        $this->error = null;
        $this->query = $this->gameVersion = $this->loaderFilter = '';
        $this->installVersion = $this->installLoader = '';
        if ($area === 'packages') {
            $this->applyPackageFilters();
        }
        if ($area === 'software') {
            $this->browseSoftware();
        }
        if (in_array($area, ['packages', 'packs'], true)) {
            $this->search();
        }
    }

    public function updatedProvider(): void
    {
        $this->clearSelectionAndSearch();
    }

    public function updatedPackageType(): void
    {
        $this->guard();
        abort_unless(in_array($this->packageType, ['mod', 'plugin'], true), 422);
        $this->rememberPackageFilters();
        $this->applyPackageFilters(false);
        $this->clearSelectionAndSearch();
    }

    public function updatedSort(): void
    {
        $this->clearSelectionAndSearch();
    }

    private function rememberPackageFilters(): void
    {
        if ($this->area === 'packages') {
            $this->packageFilterStates[$this->filterPackageType] = ['version' => $this->gameVersion, 'loader' => $this->loaderFilter];
        }
    }

    private function applyPackageFilters(bool $selectContent = true): void
    {
        $server = $this->guard();
        $runtime = Access::runtime($server, app(Access::class)->profile($server));
        $key = hash('sha256', json_encode([$runtime['software'], $runtime['version'], $runtime['loader']], JSON_THROW_ON_ERROR));
        if ($this->filterRuntimeKey !== $key) {
            $this->packageFilterStates = [];
            $this->filterRuntimeKey = $key;
            if ($selectContent) {
                $this->packageType = in_array($runtime['content_type'], ['plugin', 'proxy'], true) ? 'plugin' : 'mod';
            }
        }
        $defaults = ['version' => $runtime['version'] === 'unknown' || $runtime['content_type'] === 'proxy' ? '' : $runtime['version'],
            'loader' => ($runtime['content_type'] === $this->packageType || $runtime['content_type'] === 'proxy' && $this->packageType === 'plugin') ? $runtime['loader'] : ''];
        $filters = $this->packageFilterStates[$this->packageType] ?? $defaults;
        $this->filterPackageType = $this->packageType;
        $this->gameVersion = $filters['version'];
        $this->loaderFilter = $filters['loader'];
    }

    public function clearFilter(string $field): void
    {
        $this->guard();
        abort_unless(in_array($field, ['version', 'loader'], true), 422);
        if ($field === 'version') {
            $this->gameVersion = '';
        } else {
            $this->loaderFilter = '';
        }
        $this->clearSelectionAndSearch();
    }

    public function clearFilters(): void
    {
        $this->guard();
        $this->gameVersion = $this->loaderFilter = '';
        $this->clearSelectionAndSearch();
    }

    public function useRuntimeFilters(): void
    {
        $this->guard();
        abort_unless($this->area === 'packages', 422);
        unset($this->packageFilterStates[$this->packageType]);
        $this->applyPackageFilters(false);
        $this->clearSelectionAndSearch();
    }

    public function catalogForm(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2, 'xl' => 4])->components([
            TextInput::make('query')->label('Search')->placeholder('Project name')->maxLength(100),
            Select::make('provider')->label('Provider')->options(fn () => array_intersect_key(['modrinth' => 'Modrinth', 'curseforge' => 'CurseForge'], array_flip(array_values(array_filter(['modrinth', 'curseforge'], fn ($p) => app(Settings::class)->providerEnabled($p))))))->live()->selectablePlaceholder(false),
            Select::make('packageType')->label('Content')->options(['mod' => 'Mods', 'plugin' => 'Plugins'])->live()->selectablePlaceholder(false)->visible(fn () => $this->area === 'packages'),
            Select::make('sort')->label('Sort by')->options(['popular' => 'Popularity', 'downloads' => 'Downloads', 'updated' => 'Last updated'])->live()->selectablePlaceholder(false),
            Section::make('Filters')->compact()->collapsible()->collapsed()->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextInput::make('gameVersion')->label('Minecraft version')->placeholder('All versions')->maxLength(128),
                TextInput::make('loaderFilter')->label('Loader / platform')->placeholder('All loaders')->maxLength(128),
                Toggle::make('experimental')->label('Include beta and alpha releases'),
            ]),
        ]);
    }

    public function softwareForm(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 3])->components([
            Select::make('selectedSoftware')->label('Server software')->placeholder('Select software')->searchable()->live()->selectablePlaceholder(false)
                ->options(fn () => collect($this->types)->filter(fn ($t) => $this->experimental || !$t['experimental'] && !$t['deprecated'])->pluck('title', 'id')->all()),
            Select::make('selectedSoftwareVersion')->label('Minecraft version')->placeholder('Select version')->searchable()->live()->selectablePlaceholder(false)->disabled(fn () => $this->software === '')
                ->options(fn () => $this->versionOptions())
                ->getSearchResultsUsing(fn (string $search) => $this->searchSoftwareVersions($search))
                ->getOptionLabelUsing(fn ($value) => $value),
            Select::make('selectedBuild')->label('Build')->placeholder('Select build')->searchable()->live()->selectablePlaceholder(false)->disabled(fn () => $this->softwareVersion === '')
                ->options(fn () => collect($this->builds)->filter(fn ($b) => $this->experimental || !($b['experimental'] ?? true))->pluck('name', 'uuid')->all())
                ->getSearchResultsUsing(fn (string $search) => $this->searchSoftwareBuilds($search))
                ->getOptionLabelUsing(fn ($value) => collect($this->builds)->firstWhere('uuid', $value)['name'] ?? $value),
            Toggle::make('experimental')->label('Include experimental releases')->live()->columnSpanFull(),
        ]);
    }

    private function versionOptions(): array
    {
        return collect($this->versions)->filter(fn ($v) => $this->experimental || ($v['type'] ?? '') === 'RELEASE')->mapWithKeys(fn ($v) => [$v['id'] => $v['id'].' · '.($v['type'] ?? '')])->all();
    }

    public function searchSoftwareVersions(string $search): array
    {
        $this->guard();
        if ($this->software === '') {
            return [];
        }
        $this->page = 1;
        $this->softwareVersionQuery = Input::text($search, 100);
        $this->versions = app(McJars::class)->versions($this->software, 1, $this->softwareVersionQuery)['items'];

        return $this->versionOptions();
    }

    public function searchSoftwareBuilds(string $search): array
    {
        $this->guard();
        if ($this->softwareVersion === '') {
            return [];
        }
        $this->buildPage = 1;
        $this->softwareBuildQuery = Input::text($search, 100);
        $this->builds = app(McJars::class)->builds($this->software, $this->softwareVersion, 1, $this->softwareBuildQuery)['items'];

        return collect($this->builds)->filter(fn ($b) => $this->experimental || !($b['experimental'] ?? true))->pluck('name', 'uuid')->all();
    }

    private function clearSelectionAndSearch(): void
    {
        $this->guard();
        $this->project = $this->installVersion = $this->installLoader = '';
        $this->results = $this->releases = [];
        if (in_array($this->area, ['packages', 'packs'], true)) {
            $this->search();
        }
    }

    public function browseSoftware(): void
    {
        $this->run(function (): void {
            if (!app(Settings::class)->providerEnabled('mcjars')) {
                throw new Failure('provider_disabled');
            }
            $this->types = app(McJars::class)->types();
        });
    }

    public function chooseSoftware(string $id): void
    {
        $this->run(function () use ($id): void {
            if (!in_array($id, array_column($this->types, 'id'), true)) {
                throw new Failure('unknown_software');
            }
            $this->software = Input::id($id);
            $this->page = 1;
            $this->softwareVersionQuery = $this->softwareBuildQuery = '';
            $this->selectedSoftware = $this->software;
            $this->selectedSoftwareVersion = $this->selectedBuild = '';
            $this->softwareVersion = '';
            $this->versions = app(McJars::class)->versions($id)['items'];
            $this->builds = [];
        });
    }

    public function softwareVersions(int $page = 1): void
    {
        $this->run(function () use ($page): void {
            $this->page = min(500, max(1, $page));
            $this->versions = app(McJars::class)->versions($this->software, $this->page, $this->softwareVersionQuery)['items'];
            $this->selectedSoftwareVersion = $this->softwareVersion = $this->selectedBuild = '';
            $this->builds = [];
        });
    }

    public function chooseVersion(string $version): void
    {
        $this->run(function () use ($version): void {
            if (!in_array($version, array_column($this->versions, 'id'), true)) {
                throw new Failure('invalid_selection');
            }
            $this->softwareVersion = Input::id($version);
            $this->selectedSoftwareVersion = $this->softwareVersion;
            $this->selectedBuild = '';
            $this->buildPage = 1;
            $this->softwareBuildQuery = '';
            $this->builds = app(McJars::class)->builds($this->software, $version)['items'];
        });
    }

    public function softwareBuilds(int $page = 1): void
    {
        $this->run(function () use ($page): void {
            $this->buildPage = min(500, max(1, $page));
            $this->builds = app(McJars::class)->builds($this->software, $this->softwareVersion, $this->buildPage, $this->softwareBuildQuery)['items'];
            $this->selectedBuild = '';
        });
    }

    public function updatedSelectedSoftware(string $id): void
    {
        if ($id !== '') {
            $this->chooseSoftware($id);
        }
    }

    public function updatedSelectedSoftwareVersion(string $id): void
    {
        if ($id !== '') {
            $this->chooseVersion($id);
        }
    }

    public function closeProject(): void
    {
        $this->guard();
        $this->projectOpen = false;
    }

    public function installSelectedBuild(): void
    {
        $this->installSelected($this->selectedBuild);
    }

    public function installSelectedRelease(): void
    {
        $this->installSelected($this->selectedRelease);
    }

    private function context(): array
    {
        $this->guard();
        if (!in_array($this->packageType, ['mod', 'plugin'], true)) {
            throw new Failure('invalid_content_type');
        }
        $type = $this->area === 'packs' ? 'modpack' : $this->packageType;

        return ['type' => $type, 'sort' => $this->sort, 'version' => $this->gameVersion, 'loader' => $this->loaderFilter,
            'query' => $this->query, 'page' => $this->page, 'release_page' => $this->releasePage, 'experimental' => $this->experimental];
    }

    public function search(int $page = 1): void
    {
        $this->run(function () use ($page): void {
            abort_unless(in_array($this->area, ['packages', 'packs'], true), 422);
            $page = min(500, max(1, $page));
            $data = app(Catalog::class)->search($this->provider, [...$this->context(), 'page' => $page]);
            $this->page = $page;
            $this->rememberPackageFilters();
            $this->results = $data['items'];
            $this->total = $data['total'];
            $this->releases = [];
            $this->project = '';
            $this->projectOpen = false;
        });
    }

    public function catalogPages(): array
    {
        $last = min(500, max(1, (int) ceil($this->total / 20)));
        $current = min($last, max(1, $this->page));
        $pages = array_unique([1, ...range(max(1, $current - 2), min($last, $current + 2)), $last]);
        sort($pages);
        $result = [];
        $previous = 0;
        foreach ($pages as $page) {
            if ($page > $previous + 1) {
                $result[] = 'gap';
            } $result[] = $page;
            $previous = $page;
        }

        return $result;
    }

    public function goToCatalogPage(int $page): void
    {
        $this->guard();
        abort_unless($page >= 1 && $page <= min(500, max(1, (int) ceil($this->total / 20))), 422);
        $this->search($page);
        if ($this->error === null) {
            $this->dispatch('imm-catalog-page-changed');
        }
    }

    public function inspectProject(string $project): void
    {
        $this->run(function () use ($project): void {
            if (!in_array($project, array_column($this->results, 'id'), true)) {
                throw new Failure('invalid_selection');
            }
            $this->project = Input::id($project);
            $this->selectedRelease = '';
            $this->projectOpen = true;
            $this->installVersion = $this->gameVersion;
            $this->installLoader = $this->loaderFilter;
            $this->releasePage = 1;
            $this->loadReleases();
        });
    }

    public function loadMoreReleases(): void
    {
        $this->run(function (): void {
            abort_unless($this->projectOpen && $this->project !== '', 422);
            $this->checkReleaseFilters();
            if (!$this->releaseHasMore || $this->releasePage >= 200) {
                return;
            }
            $page = $this->releasePage;
            $this->releasePage++;
            try {
                $this->loadReleases(true);
            } catch (Throwable $error) {
                $this->releasePage = $page;
                throw $error;
            }
        });
    }

    private function loadReleases(bool $append = false): void
    {
        if (!$append) {
            $this->selectedRelease = '';
            $this->releases = [];
            $this->releaseHasMore = false;
        }
        $catalog = app(Catalog::class);
        $context = $catalog->context([...$this->context(), 'version' => $this->installVersion, 'loader' => $this->installLoader]);
        $hasMore = false;
        $releases = $catalog->releases($this->provider, $this->project, $context, $hasMore);
        $this->releases = Compatibility::orderReleases(array_values(array_column([...($append ? $this->releases : []), ...$releases], null, 'id')));
        $this->releaseHasMore = $hasMore && $this->releasePage < 200;
        $this->releaseVersion = $this->installVersion = $context['version'];
        $this->releaseLoader = $this->installLoader = $context['loader'];
        if ($this->selectedRelease === '') {
            $this->selectedRelease = $this->releases[0]['id'] ?? '';
        }
    }

    private function checkReleaseFilters(): void
    {
        if ($this->installVersion !== $this->releaseVersion || $this->installLoader !== $this->releaseLoader) {
            throw new Failure('release_filters_changed');
        }
    }

    public function refreshReleases(?string $version = null, ?string $loader = null): void
    {
        $this->run(function () use ($version, $loader): void {
            abort_unless($this->projectOpen && $this->project !== '', 422);
            if ($version !== null) {
                $this->installVersion = trim($version);
            }
            if ($loader !== null) {
                $this->installLoader = trim($loader);
            }
            $this->releasePage = 1;
            $this->loadReleases();
        });
    }

    public function quickContext(): array
    {
        $context = $this->context();
        if ($this->area === 'packs') {
            return $context;
        }
        $server = $this->guard();
        $runtime = Access::runtime($server, app(Access::class)->profile($server));
        // Browsing a different version does not change the installed runtime.
        if ($runtime['version'] !== 'unknown') {
            $context['version'] = $runtime['content_type'] === 'proxy' ? '' : $runtime['version'];
            $context['loader'] = $runtime['loader'];
        }

        return $context;
    }

    private function selectLatest(string $project): void
    {
        if (!in_array($project, array_column($this->results, 'id'), true)) {
            throw new Failure('invalid_selection');
        }
        $context = $this->quickContext();
        $this->project = Input::id($project);
        $this->installVersion = $context['version'];
        $this->installLoader = $context['loader'];
        $this->releaseVersion = $context['version'];
        $this->releaseLoader = $context['loader'];
        $this->releasePage = 1;
        $this->selectedRelease = '';
        $this->releases = [];
        $release = app(Catalog::class)->latestRelease($this->provider, $this->project, $context);
        $this->releases = $release ? [$release] : [];
        $this->selectedRelease = $release['id'] ?? '';
        if ($this->selectedRelease === '') {
            throw new Failure('exact_release_unavailable');
        }
    }

    public function installProject(string $project): void
    {
        $this->guard('plan');
        $this->run(function () use ($project): void {
            $this->selectLatest($project);
            $this->installSelectedRelease();
        });
    }

    public function queueProject(string $project): void
    {
        $this->guard('plan');
        $this->run(function () use ($project): void {
            $this->selectLatest($project);
            $this->addSelectedRelease();
        });
    }

    private function selection(string $id): Operation
    {
        $server = $this->guard('plan');
        abort_unless(in_array($this->area, ['software', 'packages', 'packs'], true), 422);
        if ($this->area === 'software') {
            if (!in_array($id, array_column($this->builds, 'uuid'), true)) {
                throw new Failure('invalid_selection');
            }
            $selection = ['kind' => 'software', 'provider' => 'mcjars', 'project' => $this->software,
                'version' => $this->softwareVersion, 'release' => Input::id($id)];
        } else {
            $this->checkReleaseFilters();
            if (!in_array($id, array_column($this->releases, 'id'), true)) {
                throw new Failure('invalid_selection');
            }
            $context = $this->context();
            $selection = ['kind' => $this->area === 'packs' ? 'pack' : 'package', 'provider' => $this->provider,
                'project' => $this->project, 'release' => Input::id($id), 'version' => $this->releaseVersion,
                'loader' => $this->releaseLoader, 'type' => $context['type']];
        }
        $selection['experimental'] = $this->experimental;

        return app(ReviewService::class)->create(auth()->user(), $server, $selection);
    }

    private function requireInstallable(Operation $operation): void
    {
        if (!($operation->plan['executable'] ?? false)) {
            throw new Failure($operation->plan['blockers'][0] ?? 'plan_not_installable');
        }
    }

    private function queueKey(Server $server): string
    {
        return 'imm.install-queue.'.$server->uuid.'.'.auth()->id();
    }

    private function savedQueue(Server $server): array
    {
        $items = session()->get($this->queueKey($server), []);

        return is_array($items) ? array_slice($items, 0, 20, true) : [];
    }

    private function saveQueue(Server $server): void
    {
        session()->put($this->queueKey($server), $this->installQueue);
    }

    public function addSelectedRelease(): void
    {
        $server = $this->guard('plan');
        $this->run(function () use ($server): void {
            abort_unless($this->area === 'packages', 422);
            $op = $this->selection($this->selectedRelease);
            $this->requireInstallable($op);
            $this->installQueue = $this->savedQueue($server);
            $key = $op->provider.':'.$op->selection['project'];
            if (!isset($this->installQueue[$key]) && count($this->installQueue) >= 20) {
                throw new Failure('batch_limit');
            }
            foreach ($this->installQueue as $existingKey => $item) {
                if ($existingKey !== $key && array_intersect_key($item['selection'], array_flip(['version', 'loader', 'type'])) !== array_intersect_key($op->selection, array_flip(['version', 'loader', 'type']))) {
                    throw new Failure('batch_context_mismatch');
                }
            }
            $this->installQueue[$key] = ['title' => collect($this->results)->firstWhere('id', $this->project)['title'] ?? $op->plan['release']['title'],
                'release' => $op->plan['release']['title'], 'selection' => array_intersect_key($op->selection, array_flip(['kind', 'provider', 'project', 'release', 'version', 'loader', 'type', 'experimental']))];
            $this->saveQueue($server);
            $this->projectOpen = false;
        });
    }

    public function removeQueued(string $key): void
    {
        $server = $this->guard('plan');
        $this->installQueue = $this->savedQueue($server);
        unset($this->installQueue[$key]);
        $this->saveQueue($server);
    }

    public function clearQueue(): void
    {
        $server = $this->guard('plan');
        $this->installQueue = [];
        $this->saveQueue($server);
    }

    public function requestInstall(string $action, string $id = ''): void
    {
        $this->guard('plan');
        abort_unless(in_array($action, ['build', 'release', 'queue', 'project'], true), 422);
        $this->pendingInstall = ['action' => $action, 'id' => match ($action) {
            'build' => $this->selectedBuild,'release' => $this->selectedRelease,default => $id
        }];
        $this->dispatch('open-modal', id: 'imm-backup-reminder');
    }

    public function clearInstallPrompt(): void
    {
        $this->pendingInstall = [];
    }

    public function startConfirmedInstall(): void
    {
        $this->guard('plan');
        $pending = $this->pendingInstall;
        $this->pendingInstall = [];
        match ($pending['action'] ?? '') {
            'build','release' => $this->installSelected($pending['id']),
            'project' => $this->installProject($pending['id']),
            'queue' => $this->installQueued(),
            default => null,
        };
        $this->dispatch('close-modal', id: 'imm-backup-reminder');
    }

    public function installQueued(): void
    {
        $server = $this->guard('plan');
        $this->run(function () use ($server): void {
            $this->installQueue = $this->savedQueue($server);
            if (!$this->installQueue) {
                throw new Failure('batch_limit');
            }
            $service = app(ReviewService::class);
            $ids = [];
            foreach ($this->installQueue as $item) {
                $op = $service->create(auth()->user(), $server, $item['selection']);
                $this->requireInstallable($op);
                $ids[] = $op->id;
            }
            $op = count($ids) === 1 ? $op : $service->createBatch(auth()->user(), $server, $ids);
            $service->queue(auth()->user(), $server, $op->id, true);
            $this->clearQueue();
            $this->projectOpen = false;
            $this->dispatch('imm-operation-queued');
        });
    }

    private function installSelected(string $id): void
    {
        $server = $this->guard('plan');
        $this->run(function () use ($server, $id): void {
            $op = $this->selection($id);
            $this->requireInstallable($op);
            app(ReviewService::class)->queue(auth()->user(), $server, $op->id, true);
            $this->projectOpen = false;
            $this->dispatch('imm-operation-queued');
        });
    }

    public function scan(): void
    {
        $server = $this->guard();
        abort_unless(auth()->user()->can('file.read', $server), 403);
        $this->run(function () use ($server): void {
            if (!in_array($this->packageType, ['mod', 'plugin'], true)) {
                throw new Failure('invalid_content_type');
            }
            $this->inventory = app(Inventory::class)->scan($server, $this->packageType);
        });
    }

    protected function getViewData(): array
    {
        $server = $this->guard();
        ViewCache::refresh(app('blade.compiler'));

        return [
            'profile' => app(Access::class)->profile($server), 'server' => $server,
            'runtime' => Access::runtime($server, app(Access::class)->profile($server)),
            'contentProviders' => array_values(array_filter(['modrinth', 'curseforge'], fn ($p) => app(Settings::class)->providerEnabled($p))),
        ];
    }
}
