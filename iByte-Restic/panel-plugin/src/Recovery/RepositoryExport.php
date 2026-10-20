<?php

namespace IByte\Restic\Recovery;

use App\Models\Node;
use App\Models\User;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RepositoryExport
{
    public static function issue(User $actor, RecoveryRepository $repository, string $password, string $backup, string $format): string
    {
        RecoveryVault::confirm($actor, $password);
        self::payload($repository, $backup, $format);
        RecoveryVault::audit('repository-export-authorized', $repository->id, $actor->id);

        return URL::temporarySignedRoute('ibyte-restic.repository-export', now()->addSeconds(60), [
            'repository' => $repository->id, 'backup' => $backup, 'format' => $format, 'user' => $actor->id, 'nonce' => (string) Str::uuid(),
        ]);
    }

    private static function payload(RecoveryRepository $repository, string $backup, string $format): array
    {
        Compatibility::assertSupported();
        Installation::assertReady();
        abort_unless(in_array($format, ['tar', 'tar.gz'], true) && Str::isUuid($backup), 409);
        $repository->refresh();
        abort_if(isset($repository->metadata['repository_deleted_at']) || isset($repository->metadata['delete_request_uuid']) || empty($repository->metadata['repository']), 409);
        $snapshot = DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repository->id)->where('backup_uuid', $backup)->whereNull('forgotten_at')->first();
        abort_unless($snapshot, 404);
        $original = DB::table('ibyte_restic_operations')->where('request_uuid', $backup)->where('operation', 'create')->first();
        $generation = $original ? (json_decode($original->payload, true, flags: JSON_THROW_ON_ERROR)['generation'] ?? 0) : 0;
        abort_unless($generation === (int) ($repository->metadata['generation'] ?? 0), 409, 'Historical generations require their recovery kit and original repository copy.');

        return ['host' => $repository->host_id, 'node' => $repository->node_id, 'server' => $repository->server_uuid,
            'profile' => $repository->profile, 'backend' => $repository->backend, 'generation' => $generation,
            'backup' => $backup, 'request_id' => $backup, 'operation' => 'download', 'archive_format' => $format,
            'repository_fingerprint' => hash('sha256', $repository->metadata['repository'])];
    }

    public function __invoke(Request $request, RecoveryRepository $repository, Transport $transport): StreamedResponse
    {
        RecoveryVault::authorize($request->user());
        abort_unless($request->isSecure() && (string) $request->user()->id === (string) $request->query('user'), 403);
        $payload = self::payload($repository, (string) $request->query('backup'), (string) $request->query('format'));
        abort_unless(Cache::add('ibyte-restic:repo-export:'.hash('sha256', $request->fullUrl()), true, 120), 410);
        $node = Node::whereKey($repository->node_id)->where('scheme', 'https')->firstOrFail();
        $ready = $transport->client($node)->get('/api/restic/readiness')->throw()->json();
        abort_unless(($ready['version'] ?? null) === '3.0.0-preview.2' && ($ready['retained_export'] ?? null) === 'v1' && ($ready['ready'] ?? false) === true && ($ready['profiles'][$repository->profile] ?? null) === $repository->backend, 409);
        $response = $transport->client($node)->timeout(1800)->withOptions(['stream' => true])->post('/api/restic/repository-export', $payload)->throw();
        $body = $response->toPsrResponse()->getBody();
        RecoveryVault::audit('repository-export-started', $repository->id, $request->user()->id);

        return response()->streamDownload(function () use ($body) {
            try {
                while (! $body->eof() && ! connection_aborted()) {
                    echo $body->read(65536);
                }
            } finally {
                $body->close();
            }
        }, $payload['backup'].'.'.$payload['archive_format'], ['Content-Type' => $payload['archive_format'] === 'tar.gz' ? 'application/gzip' : 'application/x-tar', 'Cache-Control' => 'no-store, private', 'X-Accel-Buffering' => 'no', 'X-Content-Type-Options' => 'nosniff']);
    }
}
