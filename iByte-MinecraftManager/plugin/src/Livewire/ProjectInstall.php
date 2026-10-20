<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Livewire;

use App\Models\Server;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Failure;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** Isolated, viewport-lazy metadata: catalog search never waits on twenty release requests. */
final class ProjectInstall extends Component
{
    #[Locked] public int $serverId;
    #[Locked] public string $serverUuid;
    #[Locked] public string $provider;
    #[Locked] public string $project;
    #[Locked] public array $context = [];
    #[Locked] public bool $clientOnly = false;

    public function placeholder(array $params = []): \Illuminate\Contracts\View\View
    {
        return view('ibyte-minecraft-manager::project-install-placeholder', ['queue'=>($params['context']['type'] ?? '') !== 'modpack']);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $server = Server::find($this->serverId);
        abort_unless($server && $server->uuid === $this->serverUuid, 403);
        app(Access::class)->authorize(auth()->user(), $server);
        $release = null; $error = null;
        try {
            if (!$this->clientOnly) { $release = app(Catalog::class)->latest($this->provider, $this->project, $this->context); }
        } catch (Failure $failure) { $error = $failure->reason; }
        catch (Throwable) { $error = 'request_failed'; }
        $key = 'ibyte-minecraft-manager::messages.errors.'.$error;
        $errorMessage = $error ? (app('translator')->has($key) ? __($key) : __('ibyte-minecraft-manager::messages.errors.request_failed')) : null;
        return view('ibyte-minecraft-manager::project-install', compact('server', 'release', 'error', 'errorMessage'));
    }
}
