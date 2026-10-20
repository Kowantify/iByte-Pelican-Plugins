<?php

namespace IByte\PrometheusExporter\Console;

use App\Models\Node;
use App\Models\Server;
use IByte\PrometheusExporter\Support\Normalizer;
use IByte\PrometheusExporter\Support\Settings;
use Illuminate\Console\Command;
use IByte\PrometheusExporter\Support\WingsClient;
use Illuminate\Support\Facades\Cache;
use IByte\PrometheusExporter\Support\Events;

final class Collect extends Command
{
    protected $signature = 'ibyte:prometheus-collect';
    protected $description = 'Collect Pelican node, server, backup and panel metrics for the exporter cache';

    public function handle(): int
    {
        // Also protects direct CLI invocation, which bypasses scheduler mutexes.
        $lock = Cache::lock('ibyte-prometheus:collector', 120);
        if (!$lock->get()) return self::SUCCESS;
        try {
            return $this->collect();
        } finally {
            $lock->release();
        }
    }

    private function collect(): int
    {
        $settings = Settings::read();
        // An active scrape key IS the enable switch; no key means nothing collects.
        if ($settings['token_hash'] === '') return self::SUCCESS;
        $generation = $settings['generation'];
        $nodes = Node::query()->orderBy('id')->get();
        if ($nodes->isEmpty()) return self::SUCCESS;

        try {
            $responses = WingsClient::collect($nodes, 3, 10);
        } catch (\Throwable $error) {
            Events::write('ibyte_prometheus_batch_failed', ['reason' => get_class($error)]);
            $responses = [];
        }

        // Success is per node: a failing Wings daemon never stalls the others.
        $binaryPrefix = (bool) config('panel.use_binary_prefix', true);
        $bytesFactor = $binaryPrefix ? 1024 * 1024 : 1000 * 1000;
        foreach ($nodes as $node) {
            $key = "ibyte-prometheus:node:$node->id";
            $previous = Cache::get($key, []);
            $started = microtime(true);
            $snapshot = ['node_id' => (int) $node->id, 'success' => false,
                'node_name' => (string) $node->name,
                'generation' => $generation,
                'last_success' => (int) ($previous['last_success'] ?? 0),
                'collected_at' => time(), 'duration' => 0.0, 'servers' => [],
                'backups' => null,
                'capacity' => ['memory' => $node->memory > 0 ? $node->memory * $bytesFactor : null,
                    'disk' => $node->disk > 0 ? $node->disk * $bytesFactor : null,
                    'cpu' => $node->cpu > 0 ? $node->cpu / 100 : null,
                    'memory_overallocate' => (int) $node->memory_overallocate,
                    'disk_overallocate' => (int) $node->disk_overallocate,
                    'cpu_overallocate' => (int) $node->cpu_overallocate,
                    'allocations_total' => (int) $node->allocations()->count(),
                    'allocations_used' => (int) $node->allocations()->whereNotNull('server_id')->count(),
                    'servers_assigned' => (int) $node->servers()->count()],
                'consecutive_failures' => (int) ($previous['consecutive_failures'] ?? 0) + 1,
                'error' => 'unavailable'];
            try {
                $response = $responses[(int) $node->id] ?? null;
                if (!$response || $response['body'] === null) throw new \RuntimeException('Wings HTTP failure');
                $body = $response['body'];
                $rows = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) {
                    throw new \RuntimeException('Invalid Wings server list');
                }
                $inventory = Server::query()->where('node_id', $node->id)
                    ->with(['user' => fn ($query) => $query->select(['id', 'username'])])
                    ->get(['id', 'uuid', 'node_id', 'cpu', 'memory', 'disk', 'name', 'owner_id', 'status'])->keyBy('uuid');
                $seen = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $uuid = $row['configuration']['uuid'] ?? null;
                    if (!is_string($uuid) || isset($seen[$uuid]) || !$inventory->has($uuid)) continue;
                    $seen[$uuid] = true;
                    $clean = Normalizer::server($row, $inventory[$uuid], $binaryPrefix);
                    if ($clean !== null) $snapshot['servers'][] = $clean;
                }
                // Panel backup counts: successful/completed backups per node in one query.
                $backupQuery = \App\Models\Backup::query()
                    ->whereIn('server_id', $inventory->pluck('id'))->nonFailed();
                $snapshot['backups'] = ['count' => (clone $backupQuery)->count(),
                    'bytes' => (int) (clone $backupQuery)->sum('bytes')];
                // Panel server-status distribution for this node (installing/suspended/…).
                $snapshot['statuses'] = ['installing' => 0, 'install_failed' => 0,
                    'reinstall_failed' => 0, 'suspended' => 0, 'restoring_backup' => 0];
                foreach ($inventory as $serverRow) {
                    if (is_string($serverRow->status) && isset($snapshot['statuses'][$serverRow->status])) {
                        $snapshot['statuses'][$serverRow->status]++;
                    }
                }
                if (Settings::read()['generation'] !== $generation) continue;
                $snapshot['success'] = true;
                $snapshot['last_success'] = time();
                $snapshot['consecutive_failures'] = 0;
                $snapshot['error'] = '';
            } catch (\Throwable $error) {
                Events::write('ibyte_prometheus_collection_failed', ['node_id' => (int) $node->id,
                    'reason' => get_class($error)]);
            }
            $snapshot['duration'] = round(microtime(true) - $started, 6);
            if ((string) Settings::read()['generation'] === $generation) {
                Cache::put($key, $snapshot, 600);
            }
        }
        // Panel-wide facts (users, servers, allocations, eggs) — one small cached blob.
        $panel = [
            'users' => (int) \App\Models\User::count(),
            'root_admins' => \App\Models\User::query()->get()
                ->filter(fn (\App\Models\User $user) => $user->isRootAdmin())->count(),
            'nodes' => (int) $nodes->count(),
            'nodes_maintenance' => (int) $nodes->where('maintenance_mode', true)->count(),
            'servers' => (int) Server::count(),
            'servers_suspended' => (int) Server::query()->where('status', \App\Enums\ServerState::Suspended->value)->count(),
            'servers_installing' => (int) Server::query()->where('status', \App\Enums\ServerState::Installing->value)->count(),
            'servers_install_failed' => (int) Server::query()->where('status', \App\Enums\ServerState::InstallFailed->value)->count(),
            'allocations' => (int) \App\Models\Allocation::count(),
            'allocations_used' => (int) \App\Models\Allocation::whereNotNull('server_id')->count(),
            'eggs' => (int) \App\Models\Egg::count(),
        ];
        if ((string) Settings::read()['generation'] === $generation) {
            Cache::put('ibyte-prometheus:panel', $panel, 600);
        }
        return self::SUCCESS;
    }
}
