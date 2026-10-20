<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    <x-filament::section heading="Scrape key">
        <div class="space-y-4">
            <p>Status: {{ $active ? 'a scrape key is active' : 'no scrape key — collection is idle' }}.</p>
            <p>Creating a key is what enables collection. The plugin then polls every node's stock Wings API
               every five seconds and caches CPU, memory, disk, state, backup totals and collection health
               for all game servers; Prometheus scrapes <code>{{ $endpoint }}</code> with the key.</p>
            <x-filament::button color="danger" wire:click="createKey">{{ $active ? 'Rotate scrape key' : 'Create scrape key' }}</x-filament::button>
            <x-filament::button color="danger" wire:click="revokeKey">Revoke scrape access</x-filament::button>
            @if ($newKey)
                <p class="mt-3">Copy once, then store it in your Prometheus bearer token file:
                    <code class="break-all">{{ $newKey }}</code></p>
            @endif
        </div>
    </x-filament::section>
    <x-filament::section heading="Wiring Prometheus yourself">
        <ol class="list-decimal ml-5 space-y-2">
            <li>Keep the ordinary Panel scheduler running every minute (<code>php artisan schedule:run</code> via cron).</li>
            <li>Point your Prometheus at <code>{{ $endpoint }}</code> over HTTPS with the key above as the bearer token.</li>
            <li>Import the bundled Grafana dashboard JSON and choose your Prometheus data source.</li>
        </ol>
        <p>Rotating the key revokes the previous one immediately; update Prometheus before its next scrape.</p>
    </x-filament::section>
</x-filament-panels::page>
