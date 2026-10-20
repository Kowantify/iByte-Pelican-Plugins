<x-filament-panels::page>
    @php $catalog = $this->catalog(); @endphp

    @if ($catalog === null)
        <div role="alert" class="rounded-xl border border-danger p-4">
            <p class="font-semibold text-danger">Egg Changer is not fully installed.</p>
            <p class="mt-1 text-sm">The plugin's database tables are missing. Ask an administrator to re-run the plugin Import/Install action, then reload this page. No eggs can be shown until installation completes — showing nothing is intentional, never a blank "all eggs" list.</p>
        </div>
    @else
        <x-filament::section heading="This server now">
            <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                <div><p class="text-xs uppercase tracking-wide text-gray-500">Current egg</p><p class="font-semibold">{{ $catalog['current_egg_name'] }} (#{{ $catalog['current_egg_id'] }})</p></div>
                <div><p class="text-xs uppercase tracking-wide text-gray-500">Allocated RAM</p><p class="font-semibold">{{ $catalog['memory_unlimited'] ? 'Unlimited' : $catalog['memory_mi_b'] . ' MiB' }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-gray-500">Allocated disk</p><p class="font-semibold">{{ $catalog['disk_unlimited'] ? 'Unlimited' : $catalog['disk_mi_b'] . ' MiB' }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-gray-500">Owner control</p><p class="font-semibold">Owner you &mdash; subusers cannot change eggs</p></div>
            </div>
            <p class="mt-3 text-sm text-gray-500">Changing eggs performs a <strong>clean install</strong>: your current server files are deleted after a verified recovery backup is created. Existing game saves do not convert between games.</p>
        </x-filament::section>

        <x-filament::section heading="Operation progress" class="mt-4" secondary>
            @if ($catalog['operation'] && $catalog['operation']['active'])
                <div class="flex flex-wrap items-center gap-2 text-sm" wire:poll.5s>
                    <x-filament::badge color="primary">{{ $catalog['operation']['phase'] }}</x-filament::badge>
                    <span>Operation <code>{{ substr($catalog['operation']['uuid'], 0, 8) }}</code></span>
                    <span class="text-gray-500">Progress survives refresh; closing this page does not cancel the operation.</span>
                </div>
            @elseif ($catalog['operation'] && $catalog['operation']['phase'] === 'needs_reconciliation')
                <div class="space-y-1 text-sm" wire:poll.10s>
                    <p class="flex flex-wrap items-center gap-2">
                        <x-filament::badge color="warning">needs reconciliation</x-filament::badge>
                        <code>{{ substr($catalog['operation']['uuid'], 0, 8) }}</code>
                    </p>
                    <p>{{ $catalog['operation']['failure_message'] }}</p>
                    <p class="text-gray-500">Recovery backup is preserved; nothing else runs until an administrator reconciles.</p>
                </div>
            @elseif ($catalog['operation'])
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge :color="$catalog['operation']['phase'] === 'completed' ? 'success' : 'danger'">{{ $catalog['operation']['phase'] }}</x-filament::badge>
                    <span>Last operation <code>{{ substr($catalog['operation']['uuid'], 0, 8) }}</code></span>
                </p>
            @else
                <p class="text-sm">No egg changes have been run for this server yet.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Eligible eggs" class="mt-4">
            @forelse ($catalog['eggs'] as $egg)
                <div class="mb-3 rounded-xl border p-4" wire:key="egg-{{ $egg['egg_id'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ $egg['egg_name'] }}
                                @if ((int) $egg['egg_id'] === (int) $catalog['current_egg_id'])
                                    <x-filament::badge color="success">Current</x-filament::badge>
                                @endif
                            </p>
                            <p class="text-sm text-gray-500">
                                @include('ibyte-egg-changer::package-line')
                            </p>
                        </div>
                        @if ((int) $egg['egg_id'] !== (int) $catalog['current_egg_id'])
                            <div class="flex gap-2">
                                <x-filament::button wire:click="openPreview({{ $egg['egg_id'] }})">Preview clean install</x-filament::button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm">No eggs are currently eligible for this server under any enabled package. This is the configured, intentional state — ask an administrator which package your server should be in.</p>
            @endforelse
        </x-filament::section>

        @if ($preview)
            <x-filament::section heading="Preview: clean install of “{{ $preview['egg']['name'] }}”" class="mt-4">
                <div class="space-y-2 text-sm">
                    <p>Qualifying package: <strong>{{ $preview['package']['name'] ?? 'n/a' }}</strong>
                        (min RAM {{ $preview['package']['min_memory_mb'] ?? 0 }} MiB &middot; min disk {{ $preview['package']['min_disk_mb'] ?? 0 }} MiB)</p>
                    <p>Target image: <code>{{ $preview['target_image'] }}</code></p>
                    <p>Deleted: {{ $preview['deleted_scope'] }} &mdash; {{ $preview['backup_policy'] }}</p>
                    <p>{{ $preview['data_note'] }}</p>
                    @if (count($preview['variables']) > 0)
                        <p>Target variables (defaults are used; secrets stay hidden):</p>
                        <ul class="list-disc ml-5">
                            @foreach ($preview['variables'] as $variable)
                                <li>{{ $variable['name'] }} — {{ $variable['editable'] ? 'you can edit after install' : 'admin-managed' }}{{ $variable['required'] ? ' (required)' : '' }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="rounded-xl border border-danger bg-danger/10 p-3">
                        <p class="flex items-center gap-2 font-semibold text-danger">
                            <x-filament::badge color="danger">destructive</x-filament::badge>
                            This deletes {{ $preview['deleted_scope'] }} before reinstalling {{ $preview['egg']['name'] }}.
                        </p>
                        <p class="mt-1 text-xs text-gray-500">A verified backup is created first and preserved. Closing this preview does nothing; the switch only runs after you confirm below.</p>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <x-filament::button color="danger" wire:click="confirmSwitch({{ $preview['egg']['id'] }})"
                            wire:confirm="The reinstall will DELETE all top-level files of this server after creating a verified recovery backup.
|
Server: {{ $this->getRecord()->name }} — target: {{ $preview['egg']['name'] }} ({{ $preview['deleted_scope'] }})">
                            Change egg and reinstall (clean install)
                        </x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('preview', null)">Cancel preview</x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>