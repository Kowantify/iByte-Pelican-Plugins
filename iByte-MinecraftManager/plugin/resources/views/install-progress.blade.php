    @php
        $operation = $operations->first(fn($item) => $item->server_slot !== null) ?? $operations->first(fn($item) => $item->status !== 'planned');
        $active = $operation && in_array($operation->status, \IByte\MinecraftManager\Models\Operation::ACTIVE_STATUSES, true);
        $canRelease = $operation && $canRecover && $operation->server_slot !== null && ($operation->status === 'needs-reconciliation' || $operation->updated_at->lt(now()->subSeconds(1860)));
    @endphp
<div @if($active) wire:poll.2s="refreshProgress" @endif>
    @if ($error)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</p>@endif
    @if ($operation && $operation->status !== 'planned')
        <x-filament::section compact>
            <div class="imm-form" wire:key="install-progress-{{ $operation->id }}" role="status" aria-live="polite" aria-atomic="true">
                <div class="imm-row imm-between">
                    <strong>{{ $operation->displayTitle() }}</strong>
                    <x-filament::badge :color="$operation->status === 'succeeded' ? 'success' : ($operation->error_code ? 'danger' : 'primary')">{{ __('ibyte-minecraft-manager::messages.statuses.'.$operation->status) }}</x-filament::badge>
                </div>
                @php
                    $state = $operation->plan['progress'] ?? [];
                    $phase = $state['phase'] ?? '';
                    $known = $active && isset($state['total']) && $state['total'] > 0;
                    $fraction = $known ? max(0, min(1, $phase === 'download' ? ($state['fraction'] ?? $state['files']/$state['total']) : $state['files']/$state['total'])) : null;
                    $phaseLabels = ['prepare'=>'Preparing installation','resolve'=>'Resolving mod files','download'=>'Downloading','archive-check'=>'Checking archive','extract'=>'Extracting archives','archive-stage'=>'Preparing archives','scan'=>'Reading archive directories','directories'=>'Preparing directories','startup'=>'Updating startup','runtime'=>'Resolving server software','verify'=>'Verifying downloaded files','existing'=>'Checking existing files','apply'=>'Installing files','result'=>'Checking file sizes','cleanup'=>'Finishing installation','record'=>'Recording installation','rollback'=>'Restoring previous files'];
                    $label = $phaseLabels[$phase] ?? 'Preparing installation';
                @endphp
                @if ($active)
                    @if ($operation->status === 'queued')<p class="fi-section-header-description">Waiting for the install worker. Downloads will run on the server node.</p>
                    @elseif ($operation->status === 'rolling-back')<p>An older worker is still running.</p>
                    @elseif ($operation->status === 'cancelling')<p>Cancelling. Waiting for current transfers to finish. Applied files will be kept.</p>
                    @else
                        <div class="imm-row imm-between"><span>{{ $label }}</span>@if($known)<span>{{ number_format($fraction*100) }}% &middot; {{ $state['files'] }} / {{ $state['total'] }} files</span>@endif</div>
                    @endif
                    <progress class="imm-progress" max="1" @if($known)value="{{ $fraction }}"@endif aria-label="{{ $label }}"></progress>
                    @if(!empty($state['file']))<p class="fi-section-header-description imm-progress-file">{{ $state['file'] }}</p>@endif
                    @if ($operation->status === 'queued' && $operation->updated_at->lt(now()->subMinutes(2)))<p role="alert">{{ __('ibyte-minecraft-manager::messages.queue_delayed') }}</p>@endif
                @elseif ($operation->step === 'installed')
                    <p>Installed. Start the server when ready.</p>
                @endif
                @if (isset($state['elapsed_seconds']))
                    @php
                        $elapsed = $active ? max($state['elapsed_seconds'], time() - strtotime($state['started_at'])) : $state['elapsed_seconds'];
                        $phaseElapsed = $active ? max($state['phase_elapsed_seconds'], time() - strtotime($state['phase_started_at'])) : $state['phase_elapsed_seconds'];
                    @endphp
                    <p class="fi-section-header-description">{{ number_format($elapsed, 0) }} s elapsed @if($active)&middot; {{ number_format($phaseElapsed, 0) }} s in this phase @endif</p>
                    <details wire:key="install-timings-{{ $operation->id }}" wire:ignore.self><summary class="fi-section-header-description">Phase timings</summary>
                        @foreach ($state['phase_seconds'] ?? [] as $timedPhase=>$seconds)
                            <p class="fi-section-header-description">{{ $phaseLabels[$timedPhase] ?? ucfirst($timedPhase) }}: {{ number_format($seconds, 1) }} s</p>
                        @endforeach
                    </details>
                @endif
                @if ($operation->step === 'partial-files-kept')<p>Partial files were kept. Restore a backup or install again.</p>@endif
                @if ($operation->error_code)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$operation->error_code) }}</p>@endif
                @foreach ($operation->plan['failure'] ?? [] as $detail)<p>{{ $detail }}</p>@endforeach
                @if (($operation->actor_id === auth()->id() || $canManage) && in_array($operation->status,['queued','preflight','downloading','verifying','applying','verifying-result'],true))
                    <div><x-filament::button color="gray" size="sm" wire:click="cancelReview('{{ $operation->id }}')" wire:loading.attr="disabled">Cancel install</x-filament::button></div>
                @endif
                @if ($canRelease)
                    <p>An older installation is blocking new installs. Dismiss it to keep current files and release its slot.</p>
                    <x-filament::modal :id="'imm-unlock-'.$operation->id" width="md">
                        <x-slot name="trigger"><x-filament::button color="warning" size="sm">Dismiss installation</x-filament::button></x-slot>
                        <x-slot name="heading">Dismiss installation</x-slot>
                        <p>Current files and startup settings will be kept. Nothing will be restored.</p>
                        @if ($error)<p role="alert">{{ __('ibyte-minecraft-manager::messages.errors.'.$error) }}</p>@endif
                        <x-slot name="footerActions"><x-filament::button color="warning" wire:click="releaseInstall('{{ $operation->id }}')" wire:loading.attr="disabled">Dismiss installation</x-filament::button></x-slot>
                    </x-filament::modal>
                @endif
                @if ($operation->step === 'kept-files-and-unlocked')<p>Installations unlocked. Current files were kept.</p>@endif
                @if ($operation->error_code && !$operation->acknowledged_at && $operation->actor_id === auth()->id())<div><x-filament::button color="gray" size="sm" wire:click="acknowledge('{{ $operation->id }}')">Acknowledge</x-filament::button></div>@endif
            </div>
            <details class="imm-install-history" wire:key="imm-install-history" wire:ignore.self><summary class="fi-section-header-description">Recent installations</summary><div class="imm-form">
                @foreach ($operations->where('status','!=','planned')->take(10) as $previous)
                    <div wire:key="history-{{ $previous->id }}"><p>{{ $previous->displayTitle() }} &middot; {{ __('ibyte-minecraft-manager::messages.statuses.'.$previous->status) }}</p><p class="fi-section-header-description">{{ $previous->created_at->diffForHumans() }}</p>@if($previous->error_code)<p>{{ __('ibyte-minecraft-manager::messages.errors.'.$previous->error_code) }}</p>@endif</div>
                @endforeach
            </div></details>
        </x-filament::section>
    @endif
</div>
