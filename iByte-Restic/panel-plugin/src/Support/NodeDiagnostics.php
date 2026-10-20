<?php

namespace IByte\Restic\Support;

use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Server;
use IByte\Restic\Nodes\Transport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class NodeDiagnostics
{
    public static function inspect(int $hostId, int $nodeId, ?string $operation = null): array
    {
        $report = ['host_id' => $hostId, 'node_id' => $nodeId, 'ready' => false,
            'note' => 'Read-only readiness and optional saved job status. No keys are fetched, no jobs are submitted and no protection is cleared.'];
        $host = BackupHost::where('schema', 'restic')->find($hostId);
        $node = Node::find($nodeId);
        if (! $host || ! $node || ! $host->nodes()->whereKey($nodeId)->exists()) {
            return $report + ['problem' => 'Select a Restic host and one of its explicitly linked nodes.'];
        }
        if ($node->scheme !== 'https') {
            return $report + ['problem' => 'Trusted HTTPS is required. No HTTP request was sent.'];
        }
        $transport = app(Transport::class);
        try {
            $state = $transport->client($node)->timeout(10)->get('/api/restic/readiness')->throw()->json();
            $checks = ['version_matches' => ($state['version'] ?? null) === '3.0.0-preview.2', 'protocol_matches' => ($state['protocol'] ?? null) === 1,
                'addon_ready' => ($state['ready'] ?? null) === true, 'recovery_supported' => ($state['recovery_vault'] ?? null) === true,
                'profile_matches' => ($state['profiles'][$host->configuration['profile'] ?? ''] ?? null) === ($host->configuration['backend'] ?? null) && isset($host->configuration['backend'])];
            $report['checks'] = $checks;
            $report['ready'] = ! in_array(false, $checks, true);
            if (! $report['ready']) {
                $report['problem'] = 'One or more readiness checks failed. Verify Wings addon preview.2 and the approved host credential profile on this node.';
            }
            if ($operation !== null && $operation !== '') {
                $report['operation'] = self::savedResult($transport, $node, $hostId, $operation);
            }
        } catch (Throwable $exception) {
            $report['ready'] = false;
            $report['problem'] = OperationDiagnostics::transport($exception);
        }

        return $report;
    }

    private static function savedResult(Transport $transport, Node $node, int $host, string $request): array
    {
        if (! Str::isUuid($request)) {
            return ['problem' => 'Enter a complete operation UUID.'];
        }
        $row = DB::table('ibyte_restic_operations')->where('request_uuid', $request)->where('host_id', $host)->where('node_id', $node->id)->first();
        if (! $row) {
            return ['problem' => 'No operation matches this host, node and operation ID.'];
        }
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        foreach (['request_id' => $row->request_uuid, 'host' => $host, 'node' => $node->id, 'server' => $row->server_uuid, 'backup' => $row->backup_uuid, 'operation' => $row->operation] as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return ['problem' => 'The saved operation context is inconsistent. No node status request was sent.'];
            }
        }
        if (isset($payload['repository_fingerprint']) && in_array($row->operation, ['check', 'stats', 'prune'], true)) {
            $path = '/api/restic/repository-maintenance/status';
        } elseif (Server::where('uuid', $row->server_uuid)->where('node_id', $node->id)->exists()) {
            $path = '/api/servers/'.$row->server_uuid.'/restic/status';
        } else {
            return ['problem' => 'The server no longer belongs to this node. Inspect the original protected node state; no job was resubmitted.'];
        }
        $state = $transport->client($node)->timeout(10)->post($path, $payload)->throw()->json();
        foreach (['request_id', 'host', 'node', 'server', 'backup', 'operation', 'profile'] as $key) {
            if (($state[$key] ?? null) !== ($payload[$key] ?? null)) {
                return ['problem' => 'Node result identity mismatch. The response was not accepted.'];
            }
        }
        if (($state['generation'] ?? 0) !== ($payload['generation'] ?? 0) || ! in_array($state['status'] ?? null, ['running', 'succeeded', 'failed', 'unknown'], true)) {
            return ['problem' => 'Node result status or generation mismatch. The response was not accepted.'];
        }

        return ['request_uuid' => $request, 'panel_status' => $row->status, 'node_status' => $state['status'],
            'details' => in_array($state['status'], ['failed', 'unknown'], true) ? OperationDiagnostics::nodeFailure($state['error'] ?? null) : 'The saved node result is not a failure. Historical Panel state has not been changed.'];
    }
}
