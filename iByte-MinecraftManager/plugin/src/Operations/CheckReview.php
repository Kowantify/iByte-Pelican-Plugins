<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use App\Models\Server;
use App\Models\User;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class CheckReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId, public readonly bool $install = false) {}

    public function handle(Access $access, Catalog $catalog, McJars $software, Settings $settings): void
    {
        $lock = Cache::lock('minecraft-manager-worker:'.$this->operationId, 1860);
        if (!$lock->get()) {
            return;
        }
        try {
            $this->process($access, $catalog, $software, $settings);
        } finally {
            $lock->release();
        }
    }

    private function process(Access $access, Catalog $catalog, McJars $software, Settings $settings): void
    {
        // Compare-and-set gives one consumer ownership even if queue delivery repeats.
        $claimed = Operation::whereKey($this->operationId)->where('status', Status::Queued->value)
            ->update(['status' => Status::Preflight->value, 'step' => 'authorization', 'started_at' => now()]);
        if (!$claimed) {
            return;
        }
        $operation = Operation::findOrFail($this->operationId);
        try {
            $actor = User::find($operation->actor_id);
            $server = Server::find($operation->server_id);
            if (!$access->pluginEnabled() || !$actor || !$server || $server->uuid !== $operation->server_uuid || $server->node_id !== $operation->node_id) {
                throw new Failure('source_identity_or_plugin_changed');
            }
            $profile = $access->authorize($actor, $server, 'plan');
            if ($operation->expires_at->isPast() || !hash_equals($operation->digest, PlanBuilder::digest($operation->plan))
                || !hash_equals($operation->selection['fingerprint'], Access::fingerprint($server, $profile))
                || !hash_equals($operation->selection['policy_digest'], hash('sha256', json_encode($settings->values(), JSON_THROW_ON_ERROR)))) {
                throw new Failure('stale_plan');
            }
            $s = $operation->selection;
            if (($s['intent'] ?? 'review') !== ($this->install ? 'install' : 'review')) {
                throw new Failure('operation_intent_mismatch');
            }
            if ($this->install) {
                app(InstallExecutor::class)->authorize($operation);
            }
            $release = isset($s['items']) ? PackageBatch::resolve($catalog, $s['items']) : ($s['kind'] === 'software'
                ? $software->exact($s['project'], $s['version'], $s['release'])
                : $catalog->exact($s['provider'], $s['project'], $s['release']));
            if (!hash_equals(PlanBuilder::digest($operation->plan['release']), PlanBuilder::digest($release))) {
                throw new Failure('provider_release_changed');
            }
            if ($operation->fresh()->status === 'cancelling') {
                throw new Failure('install_cancelled');
            }
            if ($this->install) {
                $plan = $operation->plan;
                $plan['worker_lock_version'] = 1;
                $operation->update(['plan' => $plan]);
                app(InstallExecutor::class)->run($operation, $release);
            } else {
                $operation->update(['status' => 'succeeded', 'step' => 'metadata-checked', 'server_slot' => null, 'finished_at' => now()]);
            }
        } catch (Throwable $error) {
            $code = $error instanceof Failure ? $error->reason : ($error instanceof HttpException ? 'permission_denied' : 'review_failed');
            $cancelled = $operation->fresh()->status === 'cancelling';
            $operation->update(['status' => $cancelled ? 'cancelled' : Status::Blocked->value, 'step' => 'preflight', 'error_code' => $cancelled ? 'install_cancelled' : $code,
                'server_slot' => null, 'finished_at' => now()]);
        }
    }

    public function failed(?Throwable $error): void
    {
        Operation::find($this->operationId)?->failInstall('worker_interrupted');
    }
}
