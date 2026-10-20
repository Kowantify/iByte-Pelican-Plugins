<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\StartupModificationService;
use App\Services\Servers\VariableValidatorService;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Models\Setting;
use IByte\MinecraftManager\Support\Access;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\RuntimeVariables;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InstallExecutor
{
    public function __construct(private readonly InstallPreparer $preparer, private readonly Access $access) {}

    public function authorize(Operation $op): array
    {
        $server = Server::find($op->server_id);
        $actor = User::find($op->actor_id);
        if (!$this->access->pluginEnabled() || !$server || !$actor || $server->uuid !== $op->server_uuid || $server->node_id !== $op->node_id) {
            throw new Failure('source_identity_or_plugin_changed');
        }
        // Recheck ownership at phase boundaries and periodically during long loops.
        $current = Operation::select(['id', 'server_slot', 'status'])->findOrFail($op->id);
        if ($current->status === 'cancelling') {
            throw new Failure('install_cancelled');
        }
        if ($op->status !== 'planned' && ($current->server_slot !== $server->id || !in_array($current->status, ['queued', 'preflight', 'downloading', 'verifying', 'applying'], true))) {
            throw new Failure('operation_ownership_lost');
        }
        $action = ['software' => 'software', 'package' => 'packages', 'pack' => 'packs'][$op->kind];
        $profile = $this->access->authorize($actor, $server, $action);
        if (!hash_equals($op->selection['fingerprint'], Access::fingerprint($server, $profile))
            || !hash_equals($op->selection['policy_digest'], hash('sha256', json_encode(app(Settings::class)->values(), JSON_THROW_ON_ERROR)))) {
            throw new Failure('stale_plan');
        }
        foreach (['file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete', ...($op->kind !== 'package' ? ['startup.update'] : [])] as $permission) {
            Context::forget("users.{$actor->id}.servers.{$server->id}.$permission");
            abort_unless($actor->can($permission, $server), 403);
        }

        return [$server, $actor, $profile];
    }

    private function runtimeSettings(Server $server, User $actor, Profile $profile, array $runtime): array
    {
        $image = null;
        $java = (int) $runtime['java'];
        foreach (array_unique([$server->image, ...array_values($server->egg->docker_images ?? [])]) as $candidate) {
            if (preg_match('~(?:java[_:-]?)(\d+)(?:\D|$)~i', $candidate, $match) && (int) $match[1] === $java) {
                $image = $candidate;
                break;
            }
        }
        if (!$image) {
            throw new Failure('java_image_not_approved');
        }
        if ($image !== $server->image) {
            Context::forget("users.{$actor->id}.servers.{$server->id}.startup.docker-image");
            abort_unless($actor->can('startup.docker-image', $server), 403);
        }
        $environment = RuntimeVariables::updates($server, $runtime);
        try {
            app(VariableValidatorService::class)->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server->egg_id, array_replace(RuntimeVariables::environment($server), $environment));
        } catch (ValidationException $error) {
            throw new Failure('runtime_variables_invalid', ['variables' => array_keys($error->errors())]);
        }

        return ['startup' => RuntimeVariables::startup($server, $runtime), 'docker_image' => $image,
            'skip_scripts' => $server->skip_scripts, 'environment' => $environment];
    }

    public function run(Operation $op, array $release): void
    {
        [$server,$actor,$profile] = $this->authorize($op);
        $node = app(NodeFiles::class, ['server' => $server]);
        $node->assertStopped();
        $clock = new ProgressClock();
        $last = 0.0;
        $phase = null;
        $touched = [];
        $plan = $op->plan;
        $plan['direct_install'] = true;
        $plan['filesystem_format'] = 3;
        $op->update(['plan' => $plan, 'status' => 'downloading', 'step' => 'prepare']);
        $progress = function (array $state) use ($op, $node, $clock, &$last, &$phase): void {
            if ($phase === $state['phase'] && microtime(true) - $last < 1 && ($state['files'] ?? 0) !== ($state['total'] ?? -1)) {
                return;
            }
            $this->authorize($op);
            $node->assertStopped();
            $last = microtime(true);
            $phase = $state['phase'];
            $plan = $op->fresh()->plan;
            $plan['progress'] = $clock->snapshot($state);
            $op->update(['plan' => $plan]);
        };
        $remote = new DirectPreparation($node, $op, $progress);
        try {
            $progress(['phase' => 'prepare', 'files' => 0]);
            $prepared = $this->preparer->prepare($release, $op->selection, $remote, $progress,
                fn (array $runtime) => $this->runtimeSettings($server, $actor, $profile, $runtime));
            $settings = $prepared['runtime'] ? $this->runtimeSettings($server, $actor, $profile, $prepared['runtime']) : null;
            $this->authorize($op);
            $node->assertStopped();
            $files = $prepared['files'];
            $existingProgress = fn () => $progress(['phase' => 'existing', 'files' => 0]);
            $existingProgress();
            $existing = $node->entries(array_keys($files), $existingProgress);
            $selected = [];
            $projects = [];
            foreach ($files as $path => $file) {
                $entry = $existing[$path] ?? null;
                if ($entry && (!($entry['file'] ?? false) || ($entry['symlink'] ?? false))) {
                    throw new Failure('node_path_collision', ['file' => $path]);
                }
                $root = explode('/', $path)[0];
                $configuration = in_array($root, ['config', 'defaultconfigs', 'kubejs', 'scripts', 'patchouli_books'], true)
                    || in_array($root, ['mods', 'plugins'], true) && !str_ends_with(strtolower($path), '.jar');
                if ($configuration && $entry) {
                    continue;
                }
                $selected[$path] = $file;
                $projects[($file['owner']['provider'] ?? '').':'.($file['owner']['project'] ?? '')] = true;
            }
            $retired = [];
            foreach (DB::table('imm_inventory')->where('server_id', $server->id)->get() as $row) {
                $meta = json_decode($row->metadata, true);
                $owner = $meta['owner'] ?? [];
                $path = $row->path;
                Input::path($path);
                $retire = $op->kind === 'pack' && ($owner['kind'] ?? '') === 'pack'
                    || $prepared['runtime'] && ($owner['kind'] ?? '') === 'software'
                    || $op->kind === 'package' && ($owner['kind'] ?? '') === 'package' && isset($projects[($owner['provider'] ?? '').':'.($owner['project'] ?? '')]);
                if ($retire && !isset($files[$path]) && in_array(explode('/', $path)[0], ['mods', 'plugins', 'libraries', 'versions'], true)) {
                    $retired[] = $path;
                }
            }
            $retiredEntries = $node->entries($retired, $existingProgress);
            foreach ($retiredEntries as $path => $entry) {
                if (!($entry['file'] ?? false) || ($entry['symlink'] ?? false)) {
                    throw new Failure('node_path_collision', ['file' => $path]);
                }
            }
            $touched = array_unique([...array_keys($selected), ...$retired]);
            $plan = $op->fresh()->plan;
            $plan['direct_files'] = $touched;
            $plan['skipped'] = $prepared['skipped'] ?? [];
            $plan['source_resolutions'] = $prepared['source_resolutions'] ?? [];
            $op->update(['plan' => $plan, 'status' => 'applying', 'step' => 'replace']);
            $progress(['phase' => 'apply', 'files' => 0, 'total' => count($selected)]);
            $node->removeMany(array_keys($retiredEntries));
            $completed = 0;
            foreach (array_chunk($selected, 100, true) as $chunk) {
                $this->authorize($op);
                $node->assertStopped();
                $moves = [];
                $replace = [];
                foreach ($chunk as $path => $file) {
                    if (isset($existing[$path])) {
                        $replace[] = $path;
                    }$moves[] = ['from' => $file['remote'], 'to' => $path];
                }
                $node->removeMany($replace);
                $node->moveMany($moves, fn ($stage) => $progress(['phase' => $stage, 'files' => $completed, 'total' => count($selected)]));
                $completed += count($chunk);
                $progress(['phase' => 'apply', 'files' => $completed, 'total' => count($selected)]);
            }
            $progress(['phase' => 'startup', 'files' => 0]);
            if ($settings) {
                $this->authorize($op);
                $node->assertStopped();
                $changed = app(StartupModificationService::class)->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server, RuntimeVariables::forNativeUpdate($server, $settings));
                app(DaemonServerRepository::class)->setServer($changed)->sync();
            }
            // Startup may now differ from the original fingerprint. Recheck actual identity/access.
            $fresh = $server->fresh();
            if (!$fresh || $fresh->uuid !== $op->server_uuid || $fresh->node_id !== $op->node_id) {
                throw new Failure('source_identity_or_plugin_changed');
            }
            $this->access->authorize($actor, $fresh, ['software' => 'software', 'package' => 'packages', 'pack' => 'packs'][$op->kind]);
            if ($op->fresh()->status === 'cancelling') {
                throw new Failure('install_cancelled');
            }
            $node->assertStopped();
            $plan = $op->fresh()->plan;
            $plan['progress'] = $clock->snapshot(['phase' => 'result', 'files' => 0, 'total' => count($selected)]);
            $op->update(['status' => 'verifying-result', 'plan' => $plan]);
            $resultLast = 0.0;
            $node->checkSizes($selected, function (int $done, int $total) use ($op, $clock, &$resultLast): void {
                if (microtime(true) - $resultLast < 1 && $done !== $total) {
                    return;
                }
                $row = $op->fresh();
                if ($row->status === 'cancelling') {
                    throw new Failure('install_cancelled');
                }
                if ($row->status !== 'verifying-result' || $row->server_slot !== $row->server_id) {
                    throw new Failure('operation_ownership_lost');
                }
                $plan = $row->plan;
                $plan['progress'] = $clock->snapshot(['phase' => 'result', 'files' => $done, 'total' => $total]);
                $op->update(['plan' => $plan]);
                $resultLast = microtime(true);
            });
            if ($node->entries($retired)) {
                throw new Failure('artifact_download_failed');
            }
            $plan = $op->fresh()->plan;
            $plan['progress'] = $clock->snapshot(['phase' => 'cleanup', 'files' => 0]);
            $op->update(['plan' => $plan]);
            $remote->cleanup();
            DB::transaction(function () use ($op, $server, $selected, $retired, $prepared, $settings, $clock): void {
                $row = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                if ($row->status === 'cancelling') {
                    throw new Failure('install_cancelled');
                }
                if ($row->status !== 'verifying-result' || $row->server_slot !== $server->id) {
                    throw new Failure('operation_ownership_lost');
                }
                foreach (array_chunk($retired, 500) as $paths) {
                    DB::table('imm_inventory')->where('server_id', $server->id)->whereIn('path', $paths)->delete();
                }
                foreach ($selected as $path => $file) {
                    DB::table('imm_inventory')->updateOrInsert(['server_id' => $server->id, 'path' => $path],
                        ['metadata' => json_encode(['size' => $file['size'], 'expected_hashes' => $file['expected_hashes'] ?? [], 'integrity' => 'size-checked', 'owner' => $file['owner'], 'operation' => $op->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($prepared['runtime']) {
                    Setting::updateOrCreate(['key' => 'runtime-'.$server->id], ['value' => [...$prepared['runtime'], 'environment' => $settings['environment'], 'source' => 'installed']]);
                }
                $plan = $row->plan;
                $plan['progress'] = $clock->snapshot(['phase' => 'record', 'files' => count($selected), 'total' => count($selected)]);
                $op->update(['status' => 'succeeded', 'step' => 'installed', 'server_slot' => null, 'finished_at' => now(), 'plan' => $plan]);
            });
        } catch (Throwable $error) {
            if (!$error instanceof Failure) {
                report($error);
            }
            $cancelled = $op->fresh()->status === 'cancelling' || $error instanceof Failure && $error->reason === 'install_cancelled';
            $op->failInstall($error instanceof Failure ? $error->reason : 'installation_failed', $cancelled, $error instanceof Failure ? $error->context : []);
        } finally {
            try {
                $remote->cleanup();
            } catch (Throwable $error) {
                report($error);
            }
        }
    }
}
