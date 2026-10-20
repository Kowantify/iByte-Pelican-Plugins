<x-filament-panels::page>
    <nav aria-label="{{ __('ibyte-minecraft-manager::messages.sections') }}" class="flex flex-wrap gap-2">
        @foreach (['overview', 'settings', 'operations'] as $item)
            <x-filament::button wire:click="switchArea('{{ $item }}')" :color="$area === $item ? 'primary' : 'gray'" :aria-current="$area === $item ? 'page' : null">{{ __('ibyte-minecraft-manager::messages.'.$item) }}</x-filament::button>
        @endforeach
    </nav>
    @if ($error)<div role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</div>@endif
    @if ($message)<div role="status">{{ __('ibyte-minecraft-manager::messages.'.$message) }}</div>@endif
    <div wire:loading role="status">{{ __('ibyte-minecraft-manager::messages.loading') }}</div>

    @if (!$installed)
        <x-filament::section>
            <p>{{ __('ibyte-minecraft-manager::messages.schema_missing') }}</p>
            <x-filament::button wire:click="repairSchema">{{ __('ibyte-minecraft-manager::messages.repair_schema') }}</x-filament::button>
        </x-filament::section>
    @elseif ($area === 'overview')
        <x-filament::section>
            <x-slot name="heading">iByte Minecraft Manager · {{ \App\Models\Plugin::find('ibyte-minecraft-manager')?->version }}</x-slot>
            <p>{{ __('ibyte-minecraft-manager::messages.preview_notice') }}</p>
            <p>{{ __('ibyte-minecraft-manager::messages.health_hint') }}</p>
            <p>{{ __('ibyte-minecraft-manager::messages.scheduler_hint') }}</p>
            <p>Last installation worker start: {{ \IByte\MinecraftManager\Models\Setting::find('install-worker')?->value['last_started_at'] ?? 'Not recorded' }}</p>
            <p>CurseForge: {{ __('ibyte-minecraft-manager::messages.'.($keySet ? 'key_set' : 'key_missing')) }}</p>
            <p>{{ __('ibyte-minecraft-manager::messages.root_admin_only') }}</p>
        </x-filament::section>
        <x-filament::button wire:click="switchArea('settings')">{{ __('ibyte-minecraft-manager::messages.setup') }}</x-filament::button>
    @elseif ($area === 'settings')
        <x-filament::section>
            <x-slot name="heading">{{ __('ibyte-minecraft-manager::messages.policy') }}</x-slot>
            <form wire:submit="savePolicy" class="space-y-4">
                <label><input type="checkbox" wire:model="enabled" /> {{ __('ibyte-minecraft-manager::messages.enabled') }}</label>
                <label><input type="checkbox" wire:model="experimental" /> {{ __('ibyte-minecraft-manager::messages.allow_experimental') }}</label>
                <fieldset><legend>{{ __('ibyte-minecraft-manager::messages.sources') }}</legend>
                    @foreach (['mcjars' => 'MCJars', 'modrinth' => 'Modrinth', 'curseforge' => 'CurseForge'] as $value => $label)
                        <label><input type="checkbox" wire:model="providers" value="{{ $value }}" /> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <x-filament::button type="submit">{{ __('ibyte-minecraft-manager::messages.save') }}</x-filament::button>
            </form>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('ibyte-minecraft-manager::messages.credentials') }}</x-slot>
            <p>{{ __('ibyte-minecraft-manager::messages.'.($keySet ? 'key_set' : 'key_missing')) }}</p>
            <form wire:submit="replaceKey" class="space-y-4">
                <label>{{ __('ibyte-minecraft-manager::messages.new_key') }}
                    <x-filament::input.wrapper><x-filament::input wire:model="apiKey" type="password" autocomplete="new-password" maxlength="512" /></x-filament::input.wrapper>
                </label>
                <x-filament::button type="submit">{{ __('ibyte-minecraft-manager::messages.replace_key') }}</x-filament::button>
            </form>
            <x-filament::button color="gray" wire:click="removeKey" wire:confirm="{{ __('ibyte-minecraft-manager::messages.remove_key_confirm') }}">{{ __('ibyte-minecraft-manager::messages.remove_key') }}</x-filament::button>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('ibyte-minecraft-manager::messages.profile_setup') }}</x-slot>
            <p>{{ __('ibyte-minecraft-manager::messages.mapping_hint') }}</p>
            <form wire:submit="saveMapping" class="grid gap-4 sm:grid-cols-2">
                <label>{{ __('ibyte-minecraft-manager::messages.egg') }}<x-filament::input.wrapper><x-filament::input.select wire:model="mapping.egg_id" required>
                    <option value="">{{ __('ibyte-minecraft-manager::messages.choose') }}</option>
                    @foreach ($eggs as $egg)<option value="{{ $egg->id }}">{{ $egg->name }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
                <label><input type="checkbox" wire:model="mapping.enabled" /> {{ __('ibyte-minecraft-manager::messages.enable_profile') }}</label>
                <x-filament::button type="submit">{{ __('ibyte-minecraft-manager::messages.save_mapping') }}</x-filament::button>
            </form>
            @error('mapping.egg_id')<p role="alert">{{ $message }}</p>@enderror
            @error('mapping.enabled')<p role="alert">{{ $message }}</p>@enderror
            @if ($mappedEgg)<p role="status">{{ __('ibyte-minecraft-manager::messages.egg_access_saved', ['egg' => $mappedEgg]) }}</p>@endif
            @foreach ($profiles as $profile)
                <p>Egg #{{ $profile->egg_id }}</p>
                <p>{{ $profile->name }} · {{ __('ibyte-minecraft-manager::messages.'.($profile->enabled ? 'enabled' : 'disabled')) }}</p>
                <x-filament::button color="gray" wire:click="toggleProfile({{ $profile->id }})">{{ __('ibyte-minecraft-manager::messages.toggle_profile') }}</x-filament::button>
            @endforeach
            @if (!$policyEnabled)<p role="alert">{{ __('ibyte-minecraft-manager::messages.policy_disabled') }}</p>@endif
            <p>{{ __('ibyte-minecraft-manager::messages.affected_servers') }}</p>
            @forelse ($mappedServers as $mappedServer)
                <p>{{ $mappedServer->name }} (egg #{{ $mappedServer->egg_id }})
                    @if ($policyEnabled)<a href="{{ \IByte\MinecraftManager\Filament\Server\MinecraftManager::getUrl(panel: 'server', tenant: $mappedServer) }}">{{ __('ibyte-minecraft-manager::messages.open_manager') }}</a>
                    @else {{ __('ibyte-minecraft-manager::messages.disabled') }} @endif
                </p>
            @empty
                <p>{{ __('ibyte-minecraft-manager::messages.no_mapped_servers') }}</p>
            @endforelse
        </x-filament::section>
    @elseif ($area === 'operations')
        @include('ibyte-minecraft-manager::activity', ['admin' => true])
    @endif
</x-filament-panels::page>
