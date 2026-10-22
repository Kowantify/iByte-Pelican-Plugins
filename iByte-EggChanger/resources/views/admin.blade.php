<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert" class="space-y-1 text-sm text-danger-600">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <div class="flex flex-wrap gap-2" role="navigation" aria-label="Egg Changer administration">
        @foreach (['servers' => 'Servers', 'packages' => 'Game gates', 'recommendations' => 'Recommendations', 'operations' => 'Operations', 'diagnostics' => 'Diagnostics'] as $tab => $label)
            <x-filament::button size="sm" :color="$activeAdminTab === $tab ? 'primary' : 'gray'" :outlined="$activeAdminTab !== $tab"
                wire:click="showAdminTab('{{ $tab }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    @if ($activeAdminTab === 'servers')
    <x-filament::section heading="Server game-switch permissions" class="mt-4"
            description="Search Pelican servers and manage switching access locally. Customer switching does not depend on Paymenter being online.">
            @php
                $switchingServers = $this->getSwitchableServers();
                $switchingStates = $this->getSwitchEntitlementStates($switchingServers);
                $entitlementsInstalled = \Illuminate\Support\Facades\Schema::hasTable('ibyte_egg_changer_entitlements');
            @endphp
    
            @unless ($entitlementsInstalled)
                <p role="alert" class="text-sm text-danger-600">Entitlement database table missing. Run the plugin migrations before changing permissions.</p>
            @endunless
    
            <div class="flex flex-wrap items-end gap-4">
                <label class="block w-full max-w-lg">
                    <span class="text-sm font-medium">Find a server</span>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="serverSearch"
                            placeholder="Search name, owner, server ID or UUID..." maxlength="100" autocomplete="off" />
                    </x-filament::input.wrapper>
                </label>
                <label class="inline-flex items-center gap-2 pb-2 text-sm">
                    <x-filament::input.checkbox wire:model.live="includeInactiveServers" />
                    <span>Include suspended / installing servers</span>
                </label>
            </div>
    
            <p class="mt-2 text-xs text-gray-500">
                Showing {{ $switchingServers->firstItem() ?? 0 }}–{{ $switchingServers->lastItem() ?? 0 }}
                of {{ $switchingServers->total() }} matching servers.
                Only installed, non-suspended servers are shown by default.
            </p>
    
            <div class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($switchingServers as $server)
                    @php
                        $switchingEnabled = $switchingStates[$server->id] ?? false;
                        $serverStatus = $server->status?->value ?? 'active';
                    @endphp
                    <div wire:key="switching-server-{{ $server->id }}"
                        class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <strong class="break-words">{{ $server->name }}</strong>
                                <x-filament::badge color="gray">#{{ $server->id }}</x-filament::badge>
                                <x-filament::badge :color="$switchingEnabled ? 'success' : 'gray'">
                                    {{ $switchingEnabled ? 'Switching enabled' : 'Switching disabled' }}
                                </x-filament::badge>
                                @if ($server->status)
                                    <x-filament::badge color="warning">{{ str($serverStatus)->headline() }}</x-filament::badge>
                                @endif
                            </div>
                            <p class="mt-1 break-all text-xs text-gray-500">
                                Owner: {{ ($server->user?->name ?: $server->user?->username ?: 'Unknown') }}
                                @if ($server->user?->email) ({{ $server->user->email }}) @endif
                                &middot; UUID: {{ $server->uuid }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            @if ($switchingEnabled)
                                <x-filament::button color="danger"
                                    wire:click="setSwitchEntitlementForServer({{ $server->id }}, false)"
                                    wire:confirm="Disable game switching for this server?"
                                    wire:loading.attr="disabled" wire:target="setSwitchEntitlementForServer"
                                    :disabled="!$entitlementsInstalled">Disable switching</x-filament::button>
                            @else
                                <x-filament::button color="success"
                                    wire:click="setSwitchEntitlementForServer({{ $server->id }}, true)"
                                    wire:confirm="Enable game switching for this server?"
                                    wire:loading.attr="disabled" wire:target="setSwitchEntitlementForServer"
                                    :disabled="!$entitlementsInstalled">Enable switching</x-filament::button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-gray-500">No matching servers. Try another search or include suspended / installing servers.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $switchingServers->links() }}</div>
            <p class="mt-2 text-xs text-gray-500">Administrator changes update Pelican immediately. A subsequent Paymenter entitlement sync may change the permission again.</p>
        </x-filament::section>
    @endif
    @if ($activeAdminTab === 'packages')
        @php $packages = $this->getPackages(); @endphp
    <x-filament::section heading="Packages" class="mt-4">
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-gray-500">Minimum resource gates for switching. Pricing stays in Paymenter.</p>
                    @unless ($showPackageForm)
                        <x-filament::button size="sm" wire:click="openPackageForm">Add gate</x-filament::button>
                    @endunless
                </div>
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($packages as $package)
                    <div class="py-3" wire:key="package-{{ $package['id'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="flex flex-wrap items-center gap-2 font-semibold">
                                    {{ $package['name'] }}
                                    <x-filament::badge :color="$package['enabled'] ? 'success' : 'gray'">{{ $package['enabled'] ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                                    <x-filament::badge color="gray">sort {{ $package['sort_order'] }}</x-filament::badge>
                                </p>
                                @if ($package['description'])<p class="text-sm text-gray-500">{{ $package['description'] }}</p>@endif
                                <p class="text-sm">
                                    Minimum RAM: <strong>{{ $package['min_memory_mb'] > 0 ? $package['min_memory_mb'] . ' MiB' : 'none' }}</strong>
                                    &middot;
                                    Minimum disk: <strong>{{ $package['min_disk_mb'] > 0 ? $package['min_disk_mb'] . ' MiB' : 'none' }}</strong>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-filament::button wire:click="editPackage({{ $package['id'] }})">Edit</x-filament::button>
                                <x-filament::button wire:click="togglePackage({{ $package['id'] }})">
                                    {{ $package['enabled'] ? 'Disable' : 'Enable' }}
                                </x-filament::button>
                                <x-filament::button color="danger" wire:click="deletePackage({{ $package['id'] }})"
                                    wire:confirm="Delete this package? Only the policy mapping is deleted; servers and eggs stay untouched.">
                                    Delete
                                </x-filament::button>
                            </div>
                        </div>
                        <details class="mt-2 text-sm">
                            <summary class="cursor-pointer text-gray-500">{{ count($package['eggs']) }} approved games · View games</summary>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @forelse ($package['eggs'] as $egg)
                                    <x-filament::badge color="gray" wire:key="pkg-egg-{{ $package['id'] }}-{{ $egg['id'] }}">{{ $egg['name'] }} (#{{ $egg['id'] }})</x-filament::badge>
                                @empty
                                    <em>none</em>
                                @endforelse
                            </div>
                        </details>
                    </div>
                @empty
                    <p class="text-sm">No packages yet. Customers cannot see any eggs until at least one package exists and is enabled.</p>
                @endforelse
    
                </div>
                @if ($showPackageForm)
                <form wire:submit="submitPackage" class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">{{ $editingPackageId ? 'Edit package #' . $editingPackageId : 'New package' }}</h3>
                        <x-filament::button color="gray" type="button" wire:click="cancelEdit">Cancel</x-filament::button>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium">Package name</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="packageForm.name" placeholder="Package name" maxlength="120" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Description (optional)</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="packageForm.description" placeholder="Description (optional)" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Minimum RAM (MiB, 0 = none)</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="number" min="0" wire:model="packageForm.min_memory_mb" placeholder="0" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Minimum disk (MiB, 0 = none)</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="number" min="0" wire:model="packageForm.min_disk_mb" placeholder="0" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Sort order</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="number" min="0" wire:model="packageForm.sort_order" placeholder="0" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="flex items-center gap-2 self-end pb-1">
                            <x-filament::input.checkbox wire:model="packageForm.enabled" />
                            <span class="text-sm font-medium">Enabled</span>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium">Approved eggs (multi-select)</span>
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input.select multiple wire:model="packageForm.egg_ids" class="h-40">
                                @foreach (\App\Models\Egg::query()->orderBy('name')->get() as $egg)
                                    <option value="{{ $egg->id }}">{{ $egg->name }} (#{{ $egg->id }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ $editingPackageId ? 'Save package' : 'Create package' }}</x-filament::button>
                </form>
                @endif
            </div>
        </x-filament::section>
    @endif
    @if ($activeAdminTab === 'recommendations')
    <x-filament::section heading="Game recommendations" class="mt-4" description="Optional advice for customers. Your existing five packages still determine which games are allowed; these profiles never change gates or billing.">
            @if (!$this->recommendationsReady())
                <p role="alert" class="text-sm text-warning-600">Recommendations table is not installed. Run the plugin update/migrations first.</p>
            @else
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <label class="block w-full max-w-sm">
                        <span class="text-sm font-medium">Filter by game</span>
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input.select wire:model.live="recommendationFilterEggId">
                                <option value="">All games (up to 50 profiles)</option>
                                @foreach (\App\Models\Egg::query()->whereIn('id', \IByte\EggChanger\Models\PackageEgg::query()->distinct()->pluck('egg_id'))->orderBy('name')->get() as $egg)
                                    <option value="{{ $egg->id }}">{{ $egg->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    @unless ($showRecommendationForm)
                        <x-filament::button size="sm" wire:click="openRecommendationForm">Add use case</x-filament::button>
                    @endunless
                </div>
                <div class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->getRecommendations() as $profile)
                        <div wire:key="recommendation-{{ $profile['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <p class="font-semibold">{{ $profile['egg_name'] }} <span class="text-gray-500">&middot;</span> {{ $profile['name'] }}</p>
                                <p class="text-sm">Recommended: <strong>{{ $profile['memory_gb'] }} GB RAM</strong> / <strong>{{ $profile['disk_gb'] }} GB storage</strong></p>
                                @if ($profile['description']) <p class="text-sm text-gray-500">{{ $profile['description'] }}</p> @endif
                            </div>
                            <div class="flex gap-2">
                                <x-filament::button wire:click="editRecommendation({{ $profile['id'] }})">Edit</x-filament::button>
                                <x-filament::button color="danger" wire:click="deleteRecommendation({{ $profile['id'] }})" wire:confirm="Delete only this recommendation? Egg gates and customer servers remain unchanged.">Delete</x-filament::button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No workload profiles yet. Add a profile only for games needing usage-specific guidance (e.g. Minecraft vanilla versus modded).</p>
                    @endforelse
                </div>
                @if ($showRecommendationForm)
                <form wire:submit="saveRecommendation" class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold">{{ $editingRecommendationId ? 'Edit use case' : 'New use case' }}</h3>
                        <x-filament::button color="gray" wire:click="cancelRecommendationEdit" type="button">Cancel</x-filament::button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-sm font-medium">Game / Pelican egg</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input.select wire:model="recommendationForm.egg_id">
                                    <option value="">Select game</option>
                                    @foreach (\App\Models\Egg::query()->whereIn('id', \IByte\EggChanger\Models\PackageEgg::query()->distinct()->pluck('egg_id'))->orderBy('name')->get() as $egg)
                                        <option value="{{ $egg->id }}">{{ $egg->name }} (#{{ $egg->id }})</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Use case name</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input wire:model="recommendationForm.name" maxlength="90" placeholder="e.g. Modded / Modpacks" /></x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Recommended RAM (GB)</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="number" min="0.5" max="1024" step="0.5" wire:model="recommendationForm.memory_gb" /></x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Recommended storage (GB)</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="number" min="0.5" max="10240" step="0.5" wire:model="recommendationForm.disk_gb" /></x-filament::input.wrapper>
                        </label>
                        <label class="block md:col-span-2">
                            <span class="text-sm font-medium">Customer guidance (optional)</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input wire:model="recommendationForm.description" maxlength="400" placeholder="e.g. Large modpacks can require 8 GB+ and a fast CPU" /></x-filament::input.wrapper>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Display order</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="number" min="0" wire:model="recommendationForm.sort_order" /></x-filament::input.wrapper>
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">Recommendations are advisory and don't increase what is billed or allocated. Use GB (1 GB = 1,024 MiB). Do not promise performance based solely on RAM/storage.</p>
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ $editingRecommendationId ? 'Save changes' : 'Add use case' }}</x-filament::button>
                </form>
                @endif
            @endif
        </x-filament::section>
    @endif
    @if ($activeAdminTab === 'operations')
        @php $operations = $this->getOperations(); @endphp
    <x-filament::section heading="Operations" class="mt-4" secondary description="Recent egg-change operations and their phases. Failed and needs-reconciliation rows keep their recovery backup.">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($operations as $operation)
                    <div class="py-3" wire:key="op-{{ $operation['uuid'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="match ($operation['phase']) {
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                        'needs_reconciliation' => 'warning',
                                        default => 'primary',
                                    }">{{ $operation['phase'] }}</x-filament::badge>
                                    <code>{{ substr($operation['uuid'], 0, 8) }}</code>
                                    <span class="text-sm text-gray-500">server #{{ $operation['server_id'] }} &middot; {{ $operation['snapshot'] }}</span>
                                </p>
                                <p class="text-sm">
                                    egg {{ $operation['source_egg_id'] }} &rarr; {{ $operation['target_egg_id'] }}
                                    @if ($operation['backup_uuid'])
                                        &middot; backup: {{ $operation['backup_successful'] ? 'verified' : 'not completed' }}
                                        <code>{{ substr($operation['backup_uuid'], 0, 8) }}</code>
                                    @endif
                                </p>
                                @if ($operation['failure_message'])<p class="text-danger">{{ $operation['failure_stage'] }}: {{ $operation['failure_message'] }}</p>@endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($operation['phase'] === 'needs_reconciliation')
                                    <x-filament::button wire:click="reconcileOperation('{{ $operation['uuid'] }}', true)">Mark completed</x-filament::button>
                                    <x-filament::button color="danger" wire:click="reconcileOperation('{{ $operation['uuid'] }}', false)">Mark failed</x-filament::button>
                                @elseif ($operation['phase'] === 'queued')
                                    <x-filament::button color="danger" wire:click="cancelOperation('{{ $operation['uuid'] }}')">Cancel queued switch</x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm">No operations recorded yet.</p>
                @endforelse
            </div>
        </x-filament::section>
    @endif
    @if ($activeAdminTab === 'diagnostics')
        @php $diagnostics = $this->getDiagnostics(); @endphp
    <x-filament::section heading="Diagnostics" secondary>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="$diagnostics['ready'] ? 'success' : 'danger'">
                    {{ $diagnostics['ready'] ? 'ready' : $diagnostics['status'] }}
                </x-filament::badge>
                @foreach ($diagnostics['contracts'] as $name => $ok)
                    <x-filament::badge :color="$ok ? 'gray' : 'danger'" wire:key="contract-{{ $name }}">{{ $name }}</x-filament::badge>
                @endforeach
            </div>
            @if ($diagnostics['error'])<p class="mt-2 text-danger">{{ $diagnostics['error'] }}</p>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
