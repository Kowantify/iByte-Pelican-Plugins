<?php

namespace IByte\Restic\Nodes;

use App\Models\Backup;
use App\Models\Node;
use App\Repositories\Daemon\DaemonBackupRepository;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Transport
{
    public function client(Node $node): PendingRequest
    {
        // Use the upstream daemon macro, bearer token, node identity header and TLS checks.
        return (new DaemonBackupRepository)->setNode($node)->getHttpClient()
            ->withOptions(['verify' => true])->connectTimeout(5)->timeout(30)->withoutRedirecting()
            ->afterResponse(function (Response $response) use ($node) {
                $expected = 'Pelican Wings/v1.0.0-rc.1+ibyte.3.0.0-preview.2 (id:'.$node->daemon_token_id.')';
                if (! hash_equals($expected, $response->header('User-Agent') ?? '')) {
                    throw new RuntimeException('Restic node identity/version handshake failed.');
                }
            });
    }

    public function context(Backup $backup, bool $readiness = true): array
    {
        Compatibility::assertSupported();
        Installation::assertReady();
        $host = $backup->backupHost;
        if ($host->schema !== 'restic' || ! ($host->configuration['staging_enabled'] ?? false)) {
            // The pinned native Create action catches HttpException and reads this
            // header directly. Zero means no timed retry; configuration must change.
            throw new HttpException(409,
                'Restic is staging-only and requires administrator opt-in. In Admin > Restic Backups > Administrative > Preview settings, select the assigned host and enable "Enable preview on disposable staging servers" only for a disposable test node. Leave restore tests disabled.',
                headers: ['Retry-After' => 0]);
        }
        $node = $backup->server->node;
        $repository = RecoveryRepository::where('host_id', $host->id)->where('node_id', $node->id)->where('server_uuid', $backup->server->uuid)->where('profile', $host->configuration['profile'])->first(['metadata']);
        if (! $host->nodes()->whereKey($node->id)->exists()) {
            throw new HttpException(409, 'Restic requires an explicit native host-to-node link. Ask an administrator to edit the original Restic host in Admin > Backup Hosts and restore its original node under Linked Nodes. Global fallback hosts are refused; do not change repository credentials or move the server.', headers: ['Retry-After' => 0]);
        }
        $existing = DB::table('ibyte_restic_operations')->where('backup_uuid', $backup->uuid)->first();
        if ($existing && ((int) $existing->node_id !== $node->id || $existing->server_uuid !== $backup->server->uuid || (int) $existing->host_id !== $host->id)) {
            throw new RuntimeException('Server moved or host identity changed: manual reconciliation required.');
        }
        if ($readiness) {
            $ready = $this->client($node)->get('/api/servers/'.$backup->server->uuid.'/restic/readiness')->throw()->json();
            if (($ready['version'] ?? '') !== '3.0.0-preview.2' || ($ready['protocol'] ?? 0) !== 1 || ($ready['ready'] ?? false) !== true || ($ready['profiles'][$host->configuration['profile']] ?? '') !== $host->configuration['backend']) {
                throw new RuntimeException('Restic node is not ready or has an incompatible addon version.');
            }
        }

        $context = [
            'host' => $host->id, 'node' => $node->id,
            'profile' => $host->configuration['profile'], 'server' => $backup->server->uuid,
            'backend' => $host->configuration['backend'],
            'backup' => $backup->uuid,
        ];
        // Existing backups stay bound to their persisted generation. A fresh
        // native creation uses the current escrow generation after capture.
        $generation = $existing ? (json_decode($existing->payload, true)['generation'] ?? 0) : ($repository?->metadata['generation'] ?? 0);
        if ($generation > 0) {
            $context['generation'] = (int) $generation;
        }

        return $context;
    }

    public function request(Backup $backup, string $endpoint, array $payload): array
    {
        $this->context($backup, false);

        return $this->client($backup->server->node)
            ->post('/api/servers/'.$backup->server->uuid.'/restic/'.$endpoint, $payload)
            ->throw()->json();
    }
}
