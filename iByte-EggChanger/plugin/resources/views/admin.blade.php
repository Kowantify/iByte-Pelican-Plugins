<x-filament-panels::page>
    @php $diagnostics = $this->getDiagnostics(); $packages = $this->getPackages(); $operations = $this->getOperations(); @endphp

    @if ($errors->any())
        <div role="alert" class="mb-4">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

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

    <x-filament::section heading="Packages" class="mt-4">
        <div class="space-y-4">
            @forelse ($packages as $package)
                <div class="rounded-xl border p-4" wire:key="package-{{ $package['id'] }}">
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
                            <span class="text-sm">{{ $package['enabled'] ? 'Enabled' : 'Disabled' }}</span>
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
                    <p class="mt-2 flex flex-wrap items-center gap-1 text-sm">
                        <span class="text-gray-500">Approved eggs:</span>
                        @forelse ($package['eggs'] as $egg)
                            <x-filament::badge color="gray" wire:key="pkg-egg-{{ $package['id'] }}-{{ $egg['id'] }}">{{ $egg['name'] }} (#{{ $egg['id'] }})</x-filament::badge>
                        @empty
                            <em>none</em>
                        @endforelse
                    </p>
                </div>
            @empty
                <p class="text-sm">No packages yet. Customers cannot see any eggs until at least one package exists and is enabled.</p>
            @endforelse

            <form wire:submit="submitPackage" class="rounded-xl border p-4 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">{{ $editingPackageId ? 'Edit package #' . $editingPackageId : 'New package' }}</h3>
                    @if ($editingPackageId)
                        <x-filament::button color="gray" wire:click="cancelEdit">Cancel edit</x-filament::button>
                    @endif
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
        </div>
    </x-filament::section>

    <x-filament::section heading="Operations" class="mt-4" secondary description="Recent egg-change operations and their phases. Failed and needs-reconciliation rows keep their recovery backup.">
        <div class="space-y-3">
            @forelse ($operations as $operation)
                <div class="rounded-xl border p-3" wire:key="op-{{ $operation['uuid'] }}">
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
</x-filament-panels::page>