<?php

namespace IByte\Restic\Backups;

use App\Enums\SubuserPermission;
use App\Models\Backup;
use IByte\Restic\Nodes\Transport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadController
{
    public function __invoke(Request $request, Backup $backup, Transport $transport): StreamedResponse
    {
        abort_unless((string) $request->user()->id === (string) $request->query('user') && $request->user()->can(SubuserPermission::BackupDownload, $backup->server), 403);
        abort_unless($backup->is_successful && $backup->completed_at && $backup->backupHost->schema === 'restic', 409);
        $once = 'ibyte-restic:download:'.hash('sha256', $request->fullUrl());
        abort_unless(Cache::add($once, true, 180), 410);
        $payload = $transport->context($backup) + ['operation' => 'download', 'request_id' => $backup->uuid];
        $response = $transport->client($backup->server->node)->timeout(1800)->withOptions(['stream' => true])
            ->post('/api/servers/'.$backup->server->uuid.'/restic/export', $payload)->throw();
        $body = $response->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($body) {
            try {
                while (! $body->eof()) {
                    echo $body->read(65536);
                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                $body->close();
            }
        }, $backup->uuid.'.tar', ['Content-Type' => 'application/x-tar', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }
}
