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
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReviewService
{
    public function __construct(private readonly Access $access, private readonly Catalog $catalog,
        private readonly McJars $software, private readonly Settings $settings) {}

    public function create(User $actor, Server $server, array $selection): Operation
    {
        $profile = $this->access->authorize($actor, $server, 'plan');
        if (!in_array($selection['kind'] ?? '', ['software', 'package', 'pack'], true)) {
            throw new Failure('invalid_selection');
        }
        $selection = array_intersect_key($selection, array_flip(['kind', 'provider', 'project', 'release', 'version', 'loader', 'type', 'experimental']));
        foreach (['provider', 'project', 'release'] as $field) {
            $selection[$field] = Input::id($selection[$field] ?? '');
        }
        $selection['version'] = empty($selection['version']) ? '' : Input::id($selection['version']);
        $runtime = Access::runtime($server, $profile);
        $selection['loader'] = empty($selection['loader']) ? '' : strtolower(Input::id($selection['loader']));
        $selection['experimental'] = (bool) ($selection['experimental'] ?? false) && ($this->settings->values()['experimental'] ?? false);
        if ($selection['kind'] === 'software') {
            if ($selection['provider'] !== 'mcjars' || !$this->settings->providerEnabled('mcjars')) {
                throw new Failure('provider_disabled');
            }
            $release = $this->software->exact($selection['project'], $selection['version'], $selection['release']);
        } else {
            $expectedType = $selection['kind'] === 'pack' ? ['modpack'] : ['mod', 'plugin'];
            if (!in_array($selection['type'] ?? '', $expectedType, true)) {
                throw new Failure('content_type_mismatch');
            }
            $release = $this->catalog->exact($selection['provider'], $selection['project'], $selection['release']);
        }
        if ($selection['kind'] === 'package') {
            if ($selection['version'] === '') {
                $selection['version'] = $runtime['version'] !== 'unknown' && $runtime['content_type'] !== 'proxy' ? $runtime['version'] : (count($release['game_versions'] ?? []) === 1 ? $release['game_versions'][0] : '');
            }
            if ($selection['loader'] === '') {
                $selection['loader'] = $runtime['loader'] ?: (count($release['loaders'] ?? []) === 1 ? $release['loaders'][0] : '');
            }
            $selection['runtime_context'] = $runtime;
        }
        $selection['fingerprint'] = Access::fingerprint($server, $profile);
        $selection['policy_digest'] = hash('sha256', json_encode($this->settings->values(), JSON_THROW_ON_ERROR));
        $selection['current'] = ['software' => $runtime['software'], 'version' => $runtime['version'], 'image' => $server->image];
        $plan = (new PlanBuilder())->build($release, $selection);

        return $this->store($actor, $server, $selection, $plan);
    }

    public function createBatch(User $actor, Server $server, array $reviewIds): Operation
    {
        $profile = $this->access->authorize($actor, $server, 'plan');
        if (count($reviewIds) < 1 || count($reviewIds) > 20 || count(array_unique($reviewIds)) !== count($reviewIds)) {
            throw new Failure('batch_limit');
        }
        $items = [];
        $warnings = [];
        foreach ($reviewIds as $id) {
            $op = $this->operation($actor, $server, Input::id($id));
            if ($op->actor_id !== $actor->id || $op->kind !== 'package' || isset($op->selection['items']) || $op->status !== 'planned' || $op->expires_at->isPast()
                || !($op->plan['executable'] ?? false) || !hash_equals($op->digest, PlanBuilder::digest($op->plan))
                || !hash_equals($op->selection['fingerprint'], Access::fingerprint($server, $profile))
                || !hash_equals($op->selection['policy_digest'], hash('sha256', json_encode($this->settings->values(), JSON_THROW_ON_ERROR)))) {
                throw new Failure('batch_review_expired');
            }
            $items[] = $op->selection;
            $warnings = [...$warnings, ...($op->plan['warnings'] ?? [])];
        }
        usort($items, fn ($a, $b) => strcmp($a['provider'].':'.$a['project'], $b['provider'].':'.$b['project']));
        $release = PackageBatch::resolve($this->catalog, $items);
        foreach ($release['items'] as $item) {
            $plan = (new PlanBuilder())->build($item['release'], $item['selection']);
            if (!$plan['executable']) {
                throw new Failure('plan_not_installable');
            }
        }
        $selection = $items[0];
        $selection['items'] = $items;
        $selection['provider'] = 'batch';
        $selection['project'] = 'selected';
        $selection['release'] = $release['id'];
        $plan['release'] = $release;
        $plan['warnings'] = array_values(array_unique($warnings));

        return $this->store($actor, $server, $selection, $plan);
    }

    private function store(User $actor, Server $server, array $selection, array $plan): Operation
    {
        $digest = PlanBuilder::digest($plan);
        $key = hash('sha256', json_encode([$actor->id, $server->uuid, $selection, $digest, intdiv(time(), 600)], JSON_THROW_ON_ERROR));
        // Double clicks share a live review. Terminal attempts must not trap a
        // corrected server in the same failed review for the ten-minute bucket.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $previous = Operation::where('idempotency', $key)->first();
            if (!$previous || $previous->server_slot !== null || $previous->status === 'planned' && !$previous->expires_at->isPast()) {
                break;
            }
            $key = hash('sha256', $key.':retry:'.$previous->id);
        }
        if ($attempt === 100) {
            throw new Failure('review_attempt_limit');
        }

        return Operation::firstOrCreate(['idempotency' => $key], [
            'id' => (string) Str::uuid(), 'server_id' => $server->id, 'server_uuid' => $server->uuid,
            'node_id' => $server->node_id, 'actor_id' => $actor->id, 'kind' => $selection['kind'],
            'provider' => $selection['provider'], 'status' => Status::Planned->value, 'step' => 'review',
            'selection' => $selection, 'plan' => $plan, 'digest' => $digest, 'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function operation(User $actor, Server $server, string $id): Operation
    {
        $this->access->authorize($actor, $server, 'plan');

        return Operation::where('id', $id)->where('server_id', $server->id)->where('server_uuid', $server->uuid)->firstOrFail();
    }

    public function queue(User $actor, Server $server, string $id, bool $install = false): void
    {
        $operation = $this->operation($actor, $server, $id);
        abort_unless($operation->actor_id === $actor->id, 403);
        if (!config('queue.connections.minecraft-manager')) {
            throw new Failure('install_queue_unavailable');
        }
        if ($install) {
            app(InstallExecutor::class)->authorize($operation);
            if (!($operation->plan['executable'] ?? false)) {
                throw new Failure('plan_not_installable');
            }
        }
        DB::transaction(function () use ($operation, $server, $install): void {
            $row = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== Status::Planned->value || $row->expires_at->isPast()) {
                throw new Failure('plan_expired_or_used');
            }
            if (Operation::where('server_slot', $server->id)->exists()) {
                throw new Failure('operation_in_progress');
            }
            $selection = $row->selection;
            $selection['intent'] = $install ? 'install' : 'review';
            $row->update(['status' => Status::Queued->value, 'step' => 'queued', 'server_slot' => $server->id, 'selection' => $selection]);
            $job = CheckReview::dispatch($row->id, $install)->afterCommit();
            // Metadata-only checks must not depend on a different queue worker.
            // Existing serialized jobs on the Panel queue remain valid.
            $job->onConnection('minecraft-manager')->onQueue('minecraft-manager');
        });
    }

    public function cancel(User $actor, Server $server, string $id): void
    {
        $operation = $this->operation($actor, $server, $id);
        abort_unless($operation->actor_id === $actor->id || $server->owner_id === $actor->id || $actor->isRootAdmin(), 403);
        $changed = Operation::whereKey($id)->whereIn('status', [Status::Planned->value, Status::Queued->value])
            ->update(['status' => Status::Cancelled->value, 'step' => 'cancelled', 'server_slot' => null, 'finished_at' => now()]);
        if (!$changed) {
            $changed = Operation::whereKey($id)->whereIn('status', ['preflight', 'downloading', 'verifying', 'applying', 'verifying-result'])
                ->update(['status' => 'cancelling', 'step' => 'cancel-requested']);
            if (!$changed) {
                throw new Failure('cancel_phase_not_supported');
            }
        }
    }

    /** Keep ambiguous files and backups; release only after worker exclusion. */
    public function releaseInstall(User $actor, Server $server, string $id): void
    {
        $this->access->authorize($actor, $server, 'recover');
        $operation = $this->operation($actor, $server, $id);
        // A fresh queued job must never lose delivery to an invalid unlock request.
        $this->assertReleaseState($operation, $server);
        $lock = Cache::lock('minecraft-manager-worker:'.$id, 1860);
        if (!$lock->get()) {
            throw new Failure('recovery_worker_busy');
        }
        try {
            $operation->refresh();
            $this->assertReleaseState($operation, $server);
            if ($operation->server_uuid !== $server->uuid || $operation->node_id !== $server->node_id) {
                throw new Failure('source_identity_or_plugin_changed');
            }
            app(NodeFiles::class, ['server' => $server])->assertStopped();
            DB::transaction(function () use ($operation, $server, $actor): void {
                $row = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($row->server_slot !== $server->id || $row->status !== $operation->status || !$row->updated_at->equalTo($operation->updated_at)) {
                    throw new Failure('recovery_not_available');
                }
                $row->forgetInventory();
                $plan = $row->plan;
                $plan['released_by'] = $actor->id;
                $plan['release_mode'] = 'keep-current-files';
                $plan['original_error'] = $row->error_code;
                $row->update(['status' => 'cancelled', 'step' => 'kept-files-and-unlocked', 'error_code' => null, 'server_slot' => null, 'finished_at' => now(), 'acknowledged_at' => now(), 'plan' => $plan]);
            });
        } finally {
            $lock->release();
        }
    }

    private function assertReleaseState(Operation $operation, Server $server): void
    {
        $stale = $operation->updated_at->lt(now()->subSeconds(1860));
        $active = in_array($operation->status, Operation::ACTIVE_STATUSES, true);
        if ($operation->server_slot !== $server->id || $operation->status !== 'needs-reconciliation' && !($active && $stale)) {
            throw new Failure('recovery_not_available');
        }
        if (empty($operation->plan['worker_lock_version']) && !$stale) {
            throw new Failure('recovery_worker_busy');
        }
    }

    public function acknowledge(User $actor, Server $server, string $id): void
    {
        $operation = $this->operation($actor, $server, $id);
        abort_unless($operation->actor_id === $actor->id, 403);
        $operation->update(['acknowledged_at' => $operation->acknowledged_at ?? now()]);
    }
}
