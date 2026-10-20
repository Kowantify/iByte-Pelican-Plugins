<x-filament-panels::page>
    @include('ibyte-minecraft-manager::styles')
    <div class="imm" x-on:imm-catalog-page-changed.window="$nextTick(() => window.scrollTo({top:0,behavior:'instant'}))">
        <x-filament::tabs label="Minecraft Manager">
            @foreach (['overview','software','packages','packs'] as $item)
                <x-filament::tabs.item :active="$area === $item" wire:click="switchArea('{{ $item }}')">{{ __('ibyte-minecraft-manager::messages.'.$item) }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        @include('ibyte-minecraft-manager::activity',['admin'=>false])
        <x-filament::modal id="imm-backup-reminder" width="md" x-on:modal-closed.stop="$wire.clearInstallPrompt()">
            <x-slot name="heading">Before you install</x-slot>
            <p>We recommend taking a backup before installing mods, modpacks or server software.</p>
            <p>Files and startup settings may change. Failed or cancelled installations are not rolled back.</p>
            <x-slot name="footerActions"><x-filament::button wire:click="startConfirmedInstall" wire:loading.attr="disabled">Install</x-filament::button><x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button></x-slot>
        </x-filament::modal>
        @if ($installQueue)
            <x-filament::section :heading="'Install queue ('.count($installQueue).')'" compact>
                <div class="imm-form">
                    <div class="imm-form imm-queue-items">@foreach ($installQueue as $key=>$item)
                        <div class="imm-row imm-between" wire:key="queued-{{ $key }}">
                            <div><strong>{{ $item['title'] }}</strong><p class="fi-section-header-description">{{ $item['release'] }}</p></div>
                            <x-filament::icon-button icon="tabler-x" color="gray" wire:click="removeQueued({{ \Illuminate\Support\Js::from($key) }})" :label="'Remove '.$item['title']" wire:loading.attr="disabled" />
                        </div>
                    @endforeach</div>
                    <div class="imm-row"><x-filament::button wire:click="requestInstall('queue')" wire:loading.attr="disabled">Install</x-filament::button><x-filament::button color="gray" wire:click="clearQueue" wire:loading.attr="disabled">Clear</x-filament::button></div>
                </div>
            </x-filament::section>
        @endif

        @if ($error)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</p>@endif
        <div class="imm-loading" wire:loading.delay role="status" aria-live="polite"><x-filament::loading-indicator /> <span>Loading…</span></div>
        @if ($area === 'overview')
            <x-filament::section heading="Server">
                @if (($runtime['source'] ?? '') === 'configured')<p class="fi-section-header-description">Using the Minecraft version and server type from Startup.</p>@endif
                <dl class="imm-summary">
                    <div><dt>{{ $runtime['content_type'] === 'proxy' ? 'Proxy version' : 'Minecraft version' }}</dt><dd>{{ $runtime['version'] === 'unknown' ? __('ibyte-minecraft-manager::messages.unknown') : $runtime['version'] }}</dd></div>
                    <div><dt>Loader / platform</dt><dd>{{ $runtime['loader'] ?: 'Unknown' }}</dd></div>
                    <div><dt>Enabled egg</dt><dd>{{ $profile->name }}</dd></div>
                </dl>
                <div class="imm-row"><x-filament::button wire:click="switchArea('software')">Server software</x-filament::button><x-filament::button color="gray" wire:click="switchArea('packages')">Mods &amp; Plugins</x-filament::button><x-filament::button color="gray" wire:click="switchArea('packs')">Modpacks</x-filament::button></div>
            </x-filament::section>
        @elseif ($area === 'software')
            <x-filament::section heading="Server software" description="Select software, Minecraft version and build. The installed launcher is server.jar.">
                <div class="imm-software-form">{{ $this->softwareForm }}</div>
                <div class="imm-row">
                    @can('minecraft-manager.plan',$server)<x-filament::button wire:click="requestInstall('build')" wire:loading.attr="disabled" :disabled="!$selectedBuild">Install</x-filament::button>@endcan
                    <x-filament::button color="gray" wire:click="browseSoftware">Refresh</x-filament::button>
                    @if ($software)<x-filament::button color="gray" wire:click="softwareVersions({{ $page+1 }})">More versions</x-filament::button>@endif
                    @if ($softwareVersion)<x-filament::button color="gray" wire:click="softwareBuilds({{ $buildPage+1 }})">More builds</x-filament::button>@endif
                </div>
            </x-filament::section>
        @elseif (in_array($area,['packages','packs'],true))
            <x-filament::section :heading="$area === 'packs' ? 'Modpacks' : 'Mods & Plugins'">
                @if ($area === 'packs')<p class="fi-section-header-description">The Minecraft version and loader required by the pack are installed automatically.</p>@endif
                <form wire:submit="search(1)" class="imm-form">{{ $this->catalogForm }}<div><x-filament::button type="submit" wire:loading.attr="disabled">Search</x-filament::button></div></form>
                <div class="imm-row">
                    @if ($gameVersion)<x-filament::button color="gray" size="xs" icon="tabler-x" icon-position="after" wire:click="clearFilter('version')" aria-label="Clear Minecraft version filter">Minecraft {{ $gameVersion }}</x-filament::button>@endif
                    @if ($loaderFilter)<x-filament::button color="gray" size="xs" icon="tabler-x" icon-position="after" wire:click="clearFilter('loader')" aria-label="Clear loader or platform filter">{{ implode(' / ', array_map('ucfirst', \IByte\MinecraftManager\Support\Compatibility::platforms($loaderFilter, $area === 'packs' ? 'modpack' : $packageType))) }}</x-filament::button>@endif
                    @if ($gameVersion || $loaderFilter)<x-filament::button color="gray" size="sm" wire:click="clearFilters">Clear filters</x-filament::button>@endif
                    @if ($area === 'packages' && $runtime['version'] !== 'unknown')<x-filament::button color="gray" size="sm" wire:click="useRuntimeFilters">Use server filters</x-filament::button>@endif
                </div>
            </x-filament::section>
            <div class="imm-row imm-between"><span>{{ number_format($total) }} projects</span><x-filament::badge color="gray">Page {{ $page }}</x-filament::badge></div>
            @php($quickContext = $this->quickContext())
            <div class="imm-catalog-list" wire:key="catalog-results">
                @forelse ($results as $result)
                    <article wire:key="project-{{ $provider }}-{{ $result['id'] }}">
                        <x-filament::section compact class="imm-project">
                            <div class="imm-catalog-copy"><div class="imm-project-heading">
                                @if (!empty($result['icon']))<img class="imm-thumbnail" src="{{ $result['icon'] }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" x-data="{failed:false}" x-show="!failed" x-on:error="failed=true">@endif
                                <div><h3 class="fi-section-header-heading"><button type="button" wire:click="inspectProject({{ \Illuminate\Support\Js::from($result['id']) }})">{{ $result['title'] }}</button></h3>@if (!empty($result['author']))<p class="fi-section-header-description">{{ $result['author'] }}</p>@endif</div>
                            </div>
                            <p class="imm-description fi-section-header-description">{{ $result['description'] }}</p>
                            <div class="imm-row"><x-filament::badge color="gray">{{ \Illuminate\Support\Number::abbreviate($result['downloads']??0,1) }} downloads</x-filament::badge>@if (!empty($result['updated']))<span class="fi-section-header-description">Updated {{ substr($result['updated'],0,10) }}</span>@endif<x-filament::button color="gray" size="sm" wire:click="inspectProject({{ \Illuminate\Support\Js::from($result['id']) }})" :aria-label="'View releases for '.$result['title']">View</x-filament::button></div>
                            </div>
                            <livewire:imm-project-install :server-id="$server->id" :server-uuid="$server->uuid" :provider="$provider" :project="$result['id']" :context="$quickContext" :client-only="($result['environment'] ?? '') === 'unsupported'" :key="'quick-'.$provider.'-'.$result['id'].'-'.hash('sha256',json_encode($quickContext))" lazy />
                        </x-filament::section>
                    </article>
                @empty <p>No projects found.</p> @endforelse
            </div>
            <nav class="imm-row imm-pagination" aria-label="Catalog pages">
                <x-filament::button color="gray" wire:click="goToCatalogPage({{ max(1,$page-1) }})" :disabled="$page<=1">Previous</x-filament::button>
                @foreach ($this->catalogPages() as $number)
                    @if ($number === 'gap')
                        <span aria-hidden="true">&hellip;</span>
                    @else
                        <x-filament::button :color="$number === $page ? 'primary' : 'gray'" :aria-current="$number === $page ? 'page' : null" :aria-label="'Go to page '.$number" wire:click="goToCatalogPage({{ $number }})">{{ $number }}</x-filament::button>
                    @endif
                @endforeach
                <x-filament::button color="gray" wire:click="goToCatalogPage({{ $page+1 }})" :disabled="$page*20 >= min(10000,$total)">Next</x-filament::button>
            </nav>
            @if ($area === 'packages')<x-filament::section heading="Installed files" description="Read-only scan of the selected mods or plugins directory." collapsible collapsed>@can('file.read',$server)<x-filament::button color="gray" wire:click="scan">Rescan files</x-filament::button>@endcan @foreach ($inventory as $file)<p>{{ $file['name'] }} · {{ number_format($file['size']) }} bytes</p>@endforeach</x-filament::section>@endif
        @endif
        @if ($projectOpen && $project)
            @php($selectedProject = collect($results)->firstWhere('id',$project))
            <x-filament::modal id="imm-project" class="imm-project-modal" width="2xl" :heading="$selectedProject['title']??$project" x-init="$nextTick(() => open())" x-on:modal-closed.stop="$wire.closeProject()">
                <div class="imm-form">
                    @if ($error)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</p>@endif
                    <p>{{ $selectedProject['description']??'' }}</p>
                    <form x-data="{}" x-on:submit.prevent.stop="$wire.refreshReleases($el.querySelector('[x-ref=immVersion]').value, $el.querySelector('[x-ref=immLoader]').value)" class="imm-form">
                        <div class="imm-fields">
                            <label>Minecraft version<x-filament::input.wrapper><x-filament::input x-ref="immVersion" wire:model="installVersion" maxlength="128" placeholder="All versions" /></x-filament::input.wrapper></label>
                            <label>Loader / platform<x-filament::input.wrapper><x-filament::input.select x-ref="immLoader" wire:model="installLoader"><option value="">All loaders / platforms</option>@foreach (['fabric','forge','neoforge','quilt','bukkit','spigot','paper','purpur','folia','sponge','velocity','bungeecord','waterfall'] as $platform)<option value="{{ $platform }}">{{ ucfirst($platform) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        </div>
                        <div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="refreshReleases">Search</x-filament::button></div>
                    </form>
                    <label class="imm-release-field">Release<x-filament::input.wrapper><x-filament::input.select wire:key="release-select-{{ md5(json_encode([$project,$releaseVersion,$releaseLoader,array_column($releases,'id')])) }}" wire:model.live="selectedRelease">
                        <option value="">Select release</option>
                        @foreach ($releases as $release)
                            @php($versions = $release['game_versions'] ?? [])
                            @php($platforms = array_map('ucfirst', $release['loaders'] ?? []))
                            @php($versionLabel = count($versions) > 3 ? count($versions).' versions' : (implode(', ', $versions) ?: 'not specified'))
                            @php($platformLabel = count($platforms) > 3 ? count($platforms).' platforms' : (implode(' / ', $platforms) ?: 'Platform not specified'))
                            @php($optionLabel = '['.ucfirst($release['channel']).'] '.\Illuminate\Support\Str::limit($release['title'], 60).' - Minecraft '.$versionLabel.' - '.$platformLabel)
                            <option value="{{ $release['id'] }}">{{ \Illuminate\Support\Str::limit($optionLabel, 110) }}</option>
                        @endforeach
                    </x-filament::input.select></x-filament::input.wrapper></label>
                    @php($selectedSupport = collect($releases)->firstWhere('id', $selectedRelease))
                    @if ($selectedSupport)
                        <div class="fi-section-header-description imm-release-support">
                            <p>{{ $selectedSupport['title'] }}</p>
                            <p>Supported Minecraft versions: {{ implode(', ', $selectedSupport['game_versions'] ?? []) ?: 'Not specified' }}</p>
                            <p>Loaders / platforms: {{ implode(' / ', array_map('ucfirst', $selectedSupport['loaders'] ?? [])) ?: 'Not specified' }}</p>
                        </div>
                    @endif
                    @if (!$releases)<p>No releases match the filters.</p>@endif
                    @if ($releaseHasMore)<div><x-filament::button color="gray" wire:click="loadMoreReleases" wire:loading.attr="disabled">Load more releases</x-filament::button></div>@endif
                    @php($clientOnly = ($selectedProject['environment'] ?? '') === 'unsupported')
                    @if ($clientOnly)<p class="fi-section-header-description">Client-only mods cannot be installed on a server.</p>@endif
                    <div class="imm-row">@can('minecraft-manager.plan',$server)<x-filament::button wire:click="requestInstall('release')" wire:loading.attr="disabled" :disabled="!$selectedRelease || $clientOnly">Install</x-filament::button>@if ($area === 'packages')<x-filament::button color="gray" wire:click="addSelectedRelease" wire:loading.attr="disabled" :disabled="!$selectedRelease || $clientOnly">Add to queue</x-filament::button>@endif @endcan</div>
                </div>
            </x-filament::modal>
        @endif

    </div>
</x-filament-panels::page>
