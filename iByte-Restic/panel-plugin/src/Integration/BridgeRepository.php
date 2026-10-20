<?php

namespace IByte\Restic\Integration;

use App\Models\Backup;
use App\Repositories\Daemon\DaemonBackupRepository;
use GuzzleHttp\Psr7\Response as PsrResponse;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Support\Compatibility;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

// Internal, source-hash-gated compatibility shim. No Panel core files are patched.
final class BridgeRepository extends DaemonBackupRepository
{
    public function restore(Backup $backup, ?string $url = null, bool $truncate = false): Response
    {
        if ($backup->backupHost->schema !== 'restic') {
            return parent::restore($backup, $url, $truncate);
        }
        Compatibility::assertSupported();
        if (! $this->server || $this->server->id !== $backup->server_id || ! $backup->is_successful || ! $backup->completed_at) {
            throw new RuntimeException('Invalid server or incomplete Restic backup.');
        }
        if (! $truncate) {
            throw new RuntimeException('Preview Restic restore requires native delete-all confirmation and a stopped server.');
        }
        if (! ($backup->backupHost->configuration['restore_fixture_enabled'] ?? false)) {
            throw new RuntimeException('Native Restic restore is blocked: the supported Wings write fence is missing. Only an administrator-created isolated disposable fixture may enable restore testing.');
        }
        app(Operations::class)->queue($backup, 'restore', (string) Str::uuid());

        return new Response(new PsrResponse(202));
    }
}
