<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Livewire;

use App\Models\Server;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Failure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ActivityFeed extends Component
{
    #[Locked]
    public bool $admin = false;

    #[Locked]
    public ?int $serverId = null;

    #[Locked]
    public ?string $serverUuid = null;

    #[Locked]
    public ?string $error = null;

    public function mount(bool $admin = false, ?int $serverId = null, ?string $serverUuid = null): void
    {
        $this->admin = $admin;
        $this->serverId = $serverId;
        $this->serverUuid = $serverUuid;
        $this->authorizeFeed();
    }

    private function authorizeFeed(): ?Server
    {
        if ($this->admin) {
            abort_unless(auth()->user()?->isRootAdmin(), 403);

            return null;
        }
        $server = Server::find($this->serverId);
        abort_unless($server && $server->uuid === $this->serverUuid, 403);
        app(Access::class)->authorize(auth()->user(), $server);

        return $server;
    }

    public function cancelReview(string $id): void
    {
        $server = $this->authorizeFeed();
        abort_unless($server && !$this->admin, 403);
        $this->error = null;
        try {
            app(ReviewService::class)->cancel(auth()->user(), $server, $id);
        } catch (Failure $error) {
            $this->error = $error->reason;
        }
    }

    public function acknowledge(string $id): void
    {
        $server = $this->authorizeFeed();
        abort_unless($server && !$this->admin, 403);
        app(ReviewService::class)->acknowledge(auth()->user(), $server, $id);
    }

    public function releaseInstall(string $id): void
    {
        $server = $this->authorizeFeed();
        abort_unless($server && !$this->admin, 403);
        $this->error = null;
        try {
            app(ReviewService::class)->releaseInstall(auth()->user(), $server, $id);
            $this->dispatch('close-modal', id: 'imm-unlock-'.$id);
        } catch (Failure $error) {
            $this->error = $error->reason;
        } catch (\Throwable $error) {
            if ($error instanceof HttpException) {
                throw $error;
            } report($error);
            $this->error = 'node_unavailable';
        }
    }

    #[On('imm-operation-queued')]
    public function refreshProgress(): void
    {
        $this->authorizeFeed();
    }

    public function render(): View
    {
        $server = $this->authorizeFeed();
        $operations = Operation::query();
        if ($server) {
            $operations->where('server_id', $server->id)->where('server_uuid', $server->uuid)->orderByRaw('server_slot IS NOT NULL DESC');
        }
        // Polling needs display fields, not tens of full modpack recovery journals.
        $rows = $operations->forFeed()->latest()->limit($this->admin ? 50 : 30)->get();
        foreach ($rows as $operation) {
            $plan = ['release' => ['title' => $operation->getAttribute('feed_title'), 'project_title' => $operation->getAttribute('feed_project_title')]];
            foreach (['progress', 'failure', 'source_resolutions', 'skipped'] as $key) {
                $raw = $operation->getAttribute('feed_'.$key);
                $plan[$key] = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : $raw;
                $operation->offsetUnset('feed_'.$key);
            }
            $operation->setAttribute('selection', ['intent' => $operation->getAttribute('feed_intent')]);
            $operation->setAttribute('plan', $plan);
            $operation->offsetUnset('feed_title');
            $operation->offsetUnset('feed_project_title');
            $operation->offsetUnset('feed_intent');
        }

        return view('ibyte-minecraft-manager::activity-feed', ['operations' => $rows, 'canRecover' => $server && app(Access::class)->allowed(auth()->user(), $server, 'recover'),
            'canManage' => $server && ($server->owner_id === auth()->id() || auth()->user()->isRootAdmin())]);
    }
}
