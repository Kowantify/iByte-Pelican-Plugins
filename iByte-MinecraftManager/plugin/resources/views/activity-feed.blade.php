<div @class(['imm-install-status' => !$admin])>
@if (!$admin)
    @include('ibyte-minecraft-manager::install-progress')
@else
<x-filament::section>
    <x-slot name="heading">{{ __('ibyte-minecraft-manager::messages.activity') }}</x-slot>
    <div wire:poll.8s role="status" aria-live="polite">
        @if ($error)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</p>@endif
        @forelse ($operations as $operation)
            <div class="space-y-2" wire:key="operation-{{ $operation->id }}">
                <p>{{ $operation->displayTitle() }} · {{ __('ibyte-minecraft-manager::messages.statuses.'.$operation->status) }}</p>
                @if ($operation->status === 'queued')
                    <p>{{ __('ibyte-minecraft-manager::messages.'.(($operation->selection['intent'] ?? 'review') === 'install' ? 'waiting_install' : 'waiting_check')) }}</p>
                    @if ($operation->updated_at->lt(now()->subMinutes(2)))<p role="alert">{{ __('ibyte-minecraft-manager::messages.queue_delayed') }}</p>@endif
                @endif
                @if ($operation->step === 'metadata-checked')<p>Metadata checked; no files installed. Create a fresh review to install.</p>@endif
                @if ($operation->step === 'installed')<p>Files installed; expected sizes checked. Start the server when ready.</p>@endif
                @if (in_array($operation->status, ['downloading', 'verifying'], true) && !empty($operation->plan['progress']))
                    @php($progress=$operation->plan['progress'])
                    <p>{{ $progress['phase']==='download' ? 'Downloading' : 'Verifying' }}: {{ $progress['files'] }}{{ isset($progress['total']) ? ' / '.$progress['total'] : '' }} files @if(isset($progress['bytes'])) · {{ number_format($progress['bytes']/1048576,1) }} MiB @endif</p>
                    <p>{{ $progress['file'] }}</p>
                @endif
                <p>{{ $operation->id }} · {{ $operation->provider }} · {{ $operation->created_at->toIso8601String() }}</p>
                @if ($admin)<p>{{ __('ibyte-minecraft-manager::messages.server') }} #{{ $operation->server_id }} · {{ __('ibyte-minecraft-manager::messages.actor') }} #{{ $operation->actor_id }}</p>@endif
                @if ($operation->error_code)<p>{{ __('ibyte-minecraft-manager::messages.errors.'.$operation->error_code) }}</p>@endif
                @foreach ($operation->plan['failure'] ?? [] as $detail)<p>{{ $detail }}</p>@endforeach
                @if (!empty($operation->plan['source_resolutions']))<details><summary>{{ count($operation->plan['source_resolutions']) }} files downloaded from CurseForge CDN</summary>@foreach ($operation->plan['source_resolutions'] as $file)<p>{{ $file }}</p>@endforeach</details>@endif
                @if (!empty($operation->plan['skipped']))<details><summary>{{ count($operation->plan['skipped']) }} client assets skipped</summary>@foreach ($operation->plan['skipped'] as $skipped)<p>{{ $skipped['title'] }} ({{ $skipped['project'] }})</p>@endforeach</details>@endif
                @if (!$admin && $operation->actor_id === auth()->id() && in_array($operation->status, ['planned', 'queued'], true))
                    <x-filament::button color="gray" wire:click="cancelReview('{{ $operation->id }}')">{{ __('ibyte-minecraft-manager::messages.'.(($operation->selection['intent'] ?? 'review') === 'install' ? 'cancel_install' : 'cancel')) }}</x-filament::button>
                @endif
                @if (!$admin && $operation->actor_id === auth()->id() && $operation->error_code && !$operation->acknowledged_at)
                    <x-filament::button color="gray" wire:click="acknowledge('{{ $operation->id }}')">{{ __('ibyte-minecraft-manager::messages.acknowledge') }}</x-filament::button>
                @endif
                @if ($admin && ($operation->selection['intent'] ?? 'review') === 'review' && in_array($operation->status, ['queued', 'preflight'], true) && $operation->updated_at->lt(now()->subMinutes(5)))
                    <x-filament::button color="gray" wire:click="$parent.reconcileReview('{{ $operation->id }}')">{{ __('ibyte-minecraft-manager::messages.reconcile_review') }}</x-filament::button>
                @endif
            </div>
        @empty
            <p>{{ __('ibyte-minecraft-manager::messages.activity_empty') }}</p>
        @endforelse
    </div>
</x-filament::section>
@endif
</div>
