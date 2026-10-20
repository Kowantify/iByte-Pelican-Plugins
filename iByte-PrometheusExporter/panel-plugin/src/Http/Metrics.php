<?php

namespace IByte\PrometheusExporter\Http;

use App\Models\Node;
use IByte\PrometheusExporter\Events\MetricsRendered;
use IByte\PrometheusExporter\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class Metrics
{
    /** Snapshots older than this are stale (three fixed 5-second collection intervals). */
    private const FRESH_GRACE = 15;

    public function __invoke(Request $request)
    {
        $settings = Settings::read();
        $provided = $request->bearerToken();
        if (!$provided || !$settings['token_hash'] ||
            !hash_equals($settings['token_hash'], hash('sha256', $provided))) {
            abort(401);
        }

        $lines = [];
        self::help($lines, [
            'node_collection_success' => 'Whether the latest node poll succeeded and is recent.',
            'node_info' => 'Node identity; one info series per node carrying node_name.',
            'node_last_success_timestamp_seconds' => 'Unix time when the last node poll succeeded.',
            'node_collection_age_seconds' => 'Seconds since the latest node poll attempt.',
            'node_collection_duration_seconds' => 'Duration of the latest node processing in seconds.',
            'node_consecutive_failures' => 'Consecutive failed node polls.',
            'node_memory_capacity_bytes' => 'Node-configured memory capacity in bytes (allocation basis).',
            'node_disk_capacity_bytes' => 'Node-configured disk capacity in bytes (allocation basis).',
            'node_cpu_capacity_cores' => 'Node-configured CPU capacity in logical cores (allocation basis).',
            'node_memory_overallocate_percent' => 'Percent of node memory that may be over-allocated.',
            'node_disk_overallocate_percent' => 'Percent of node disk that may be over-allocated.',
            'node_cpu_overallocate_percent' => 'Percent of node CPU that may be over-allocated.',
            'node_allocations' => 'Allocations configured on the node.',
            'node_allocations_used' => 'Allocations assigned to a server on the node.',
            'node_servers_assigned' => 'Servers assigned to the node in Panel inventory.',
            'node_backups_stored' => 'Number of non-failed panel backups stored on the node.',
            'node_backups_bytes' => 'Total size of non-failed panel backups stored on the node.',
            'node_servers_status' => 'Servers on the node with each Panel status; one series per status.',
            'server_info' => 'Server display name and owner username; one info series per server.',
            'server_state' => 'Current server runtime state; one state series per server.',
            'server_cpu_usage_ratio' => 'Server CPU use in logical cores; one core is 1.',
            'server_memory_usage_bytes' => 'Server memory use as reported by Wings.',
            'server_disk_usage_bytes' => 'Cached game-data disk use as reported by Wings.',
            'server_network_rx_bytes' => 'Bytes received by the container since start (Wings).',
            'server_network_tx_bytes' => 'Bytes sent by the container since start (Wings).',
            'server_disk_read_bytes' => 'Bytes read from disk by the container since start (Wings).',
            'server_disk_write_bytes' => 'Bytes written to disk by the container since start (Wings).',
            'server_uptime_seconds' => 'Container uptime in seconds (Wings).',
            'server_wings_memory_limit_bytes' => 'Wings-side per-process memory ceiling in bytes.',
            'server_cpu_limit_cores' => 'Configured CPU allocation in logical cores.',
            'server_memory_limit_bytes' => 'Configured memory allocation in bytes.',
            'server_disk_limit_bytes' => 'Configured game-data disk allocation in bytes.',
            'server_cpu_unlimited' => 'Whether CPU allocation is unlimited.',
            'server_memory_unlimited' => 'Whether memory allocation is unlimited.',
            'server_disk_unlimited' => 'Whether disk allocation is unlimited.',
            'panel_users' => 'Panel user accounts.',
            'panel_root_admins' => 'Panel users holding the root-admin role.',
            'panel_nodes' => 'Panel nodes.',
            'panel_nodes_maintenance' => 'Panel nodes currently in maintenance mode.',
            'panel_servers' => 'Panel servers.',
            'panel_servers_status' => 'Panel servers by status; one series per status.',
            'panel_allocations' => 'Panel allocations.',
            'panel_allocations_used' => 'Panel allocations assigned to a server.',
            'panel_eggs' => 'Panel eggs.',
        ]);

        // One bounded cached snapshot per node; rendering never calls Wings.
        $panelId = self::escape($settings['panel_id']);
        foreach (Node::query()->orderBy('id')->pluck('id') as $nodeId) {
            $snapshot = Cache::get("ibyte-prometheus:node:$nodeId", []);
            $prefix = 'panel_id="'.$panelId.'",node_id="'.(int) $nodeId.'"';
            $labels = '{'.$prefix.'}';
            $fresh = ($snapshot['generation'] ?? null) === $settings['generation'] &&
                ($snapshot['success'] ?? false) &&
                (time() - (int) ($snapshot['collected_at'] ?? 0) <= self::FRESH_GRACE);
            // Node identity is always present; usage rows below are withheld when stale.
            self::metric($lines, 'ibyte_pelican_node_info',
                '{'.$prefix.',node_name="'.self::escape((string) ($snapshot['node_name'] ?? '')).'"}', 1);
            self::metric($lines, 'ibyte_pelican_node_collection_success', $labels, $fresh ? 1 : 0);
            self::metric($lines, 'ibyte_pelican_node_last_success_timestamp_seconds', $labels, (int) ($snapshot['last_success'] ?? 0));
            if (isset($snapshot['collected_at'])) {
                self::metric($lines, 'ibyte_pelican_node_collection_age_seconds', $labels, max(0, time() - (int) $snapshot['collected_at']));
                self::metric($lines, 'ibyte_pelican_node_collection_duration_seconds', $labels, (float) $snapshot['duration']);
                self::metric($lines, 'ibyte_pelican_node_consecutive_failures', $labels, (int) ($snapshot['consecutive_failures'] ?? 0));
            }
            // Node inventory facts come from the snapshot so render stays Wings-free.
            foreach (['memory' => 'node_memory_capacity_bytes', 'disk' => 'node_disk_capacity_bytes']
                as $key => $name) {
                if (($snapshot['capacity'][$key] ?? null) !== null) {
                    self::metric($lines, 'ibyte_pelican_'.$name, $labels, (float) $snapshot['capacity'][$key]);
                }
            }
            if (($snapshot['capacity']['cpu'] ?? null) !== null) {
                self::metric($lines, 'ibyte_pelican_node_cpu_capacity_cores', $labels, (float) $snapshot['capacity']['cpu']);
            }
            foreach (['memory' => 'node_memory_overallocate_percent', 'disk' => 'node_disk_overallocate_percent',
                'cpu' => 'node_cpu_overallocate_percent'] as $key => $name) {
                self::metric($lines, 'ibyte_pelican_'.$name, $labels, (int) ($snapshot['capacity'][$key.'_overallocate'] ?? 0));
            }
            foreach (['allocations_total' => 'allocations', 'allocations_used', 'servers_assigned'] as $key) {
                $family = $key === 'allocations_used' ? 'allocations_used' : ($key === 'servers_assigned' ? 'servers_assigned' : 'allocations');
                self::metric($lines, 'ibyte_pelican_node_'.$family, $labels, (int) ($snapshot['capacity'][$key] ?? 0));
            }
            if (($snapshot['backups'] ?? null) !== null) {
                self::metric($lines, 'ibyte_pelican_node_backups_stored', $labels, (int) $snapshot['backups']['count']);
                self::metric($lines, 'ibyte_pelican_node_backups_bytes', $labels, (int) $snapshot['backups']['bytes']);
            }
            foreach (['installing', 'install_failed', 'reinstall_failed', 'suspended', 'restoring_backup'] as $status) {
                $statusLabels = $prefix.',status="'.$status.'"}';
                self::metric($lines, 'ibyte_pelican_node_servers_status', '{'.$statusLabels, (int) ($snapshot['statuses'][$status] ?? 0));
            }
            if (!$fresh) continue;
            foreach ($snapshot['servers'] ?? [] as $server) {
                $base = '{'.$prefix.',server_uuid="'.self::escape($server['uuid']).'"';
                $info = $base.',name="'.self::escape($server['name']).'",owner="'.self::escape($server['owner']).'"}';
                self::metric($lines, 'ibyte_pelican_server_info', $info, 1);
                $state = $base.',state="'.self::escape($server['state']).'"}';
                self::metric($lines, 'ibyte_pelican_server_state', $state, 1);
                foreach (['cpu' => 'cpu_usage_ratio', 'memory' => 'memory_usage_bytes',
                    'disk' => 'disk_usage_bytes', 'cpu_limit' => 'cpu_limit_cores',
                    'memory_limit' => 'memory_limit_bytes', 'disk_limit' => 'disk_limit_bytes',
                    'network_rx' => 'network_rx_bytes', 'network_tx' => 'network_tx_bytes',
                    'disk_read' => 'disk_read_bytes', 'disk_write' => 'disk_write_bytes',
                    'uptime' => 'uptime_seconds', 'wings_memory_limit' => 'wings_memory_limit_bytes'] as $key => $name) {
                    if ($server[$key] !== null) self::metric($lines, "ibyte_pelican_server_$name", $base.'}', $server[$key]);
                }
                foreach (['cpu', 'memory', 'disk'] as $kind) {
                    if ($server["unlimited_$kind"] !== null) {
                        self::metric($lines, "ibyte_pelican_server_{$kind}_unlimited", $base.'}', $server["unlimited_$kind"] ? 1 : 0);
                    }
                }
            }
        }
        // Panel-wide counts (cached by the collector; cheap single reads here).
        $panel = Cache::get('ibyte-prometheus:panel', []);
        foreach ([
            'panel_users' => $panel['users'] ?? null,
            'panel_root_admins' => $panel['root_admins'] ?? null,
            'panel_nodes' => $panel['nodes'] ?? null,
            'panel_nodes_maintenance' => $panel['nodes_maintenance'] ?? null,
            'panel_servers' => $panel['servers'] ?? null,
            'panel_allocations' => $panel['allocations'] ?? null,
            'panel_allocations_used' => $panel['allocations_used'] ?? null,
            'panel_eggs' => $panel['eggs'] ?? null,
        ] as $name => $value) {
            if ($value !== null) self::metric($lines, 'ibyte_pelican_'.$name, '{panel_id="'.$panelId.'"}', $value);
        }
        foreach (['suspended', 'installing', 'install_failed'] as $status) {
            if (isset($panel['servers_'.$status])) {
                self::metric($lines, 'ibyte_pelican_panel_servers_status',
                    '{panel_id="'.$panelId.'",status="'.$status.'"}', (int) $panel['servers_'.$status]);
            }
        }
        // Add-on extension point: listeners append their own validated families
        // (same ibyte_pelican_ namespace, same bearer key, one HTTP response).
        $rendered = new MetricsRendered($settings['panel_id']);
        event($rendered);
        foreach ($rendered->extraLines() as $line) {
            $lines[] = $line;
        }
        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store']);
    }

    /** Writes one HELP/TYPE metadata pair per metric family. */
    private static function help(array &$lines, array $metrics): void
    {
        foreach ($metrics as $suffix => $description) {
            $lines[] = '# HELP ibyte_pelican_'.$suffix.' '.$description;
            $lines[] = '# TYPE ibyte_pelican_'.$suffix.' gauge';
        }
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private static function metric(array &$lines, string $name, string $labels, int|float $value): void
    {
        if (!is_finite((float) $value)) return;
        $lines[] = $name.$labels.' '.(string) $value;
    }
}
