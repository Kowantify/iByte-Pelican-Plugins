<div class="imm-quick-install">
    <p class="fi-section-header-description">
        @if ($clientOnly)
            Client only
        @elseif ($error)
            {{ $errorMessage }}
        @elseif ($release)
            {{ $release['title'] }}
        @else
            No matching releases
        @endif
    </p>
    @if ($error)<x-filament::button color="gray" size="sm" wire:click="$refresh" wire:loading.attr="disabled">Retry</x-filament::button>@endif
    @if ($release && (($context['version'] ?? '') || ($context['loader'] ?? '')))<p class="fi-section-header-description">{{ $context['version'] ?: '' }}{{ $context['version'] && $context['loader'] ? ' · ' : '' }}{{ ucfirst($context['loader'] ?? '') }}</p>@endif
    <div class="imm-row">
        @can('minecraft-manager.plan', $server)
            <x-filament::button wire:click="$parent.requestInstall('project', {{ \Illuminate\Support\Js::from($project) }})" wire:loading.attr="disabled" :disabled="!$release">Install</x-filament::button>
            @if (($context['type'] ?? '') !== 'modpack')<x-filament::button color="gray" wire:click="$parent.queueProject({{ \Illuminate\Support\Js::from($project) }})" wire:loading.attr="disabled" :disabled="!$release">Add to queue</x-filament::button>@endif
        @endcan
    </div>
</div>
