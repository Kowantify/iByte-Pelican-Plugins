<?php

namespace IByte\Restic\Integration;

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Models\ServerTransfer;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Recovery\RepositoryRemoval;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ModelObservers
{
    public static function register(): void
    {
        ServerTransfer::creating(function (ServerTransfer $transfer) {
            $retention = app(RetainedDeletion::class);
            LifecycleGuard::assertClear($transfer->server, $retention->active($transfer->server));
            $retention->detachForTransfer($transfer->server);
        });
        Server::updating(function (Server $server) {
            if ($server->isDirty(['owner_id', 'name']) && $server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->exists()) {
                Installation::assertReady();
            }
            if ($server->isDirty('node_id')) {
                LifecycleGuard::assertClear($server);
            }
        });
        Server::deleting(fn (Server $server) => LifecycleGuard::assertClear($server, app(RetainedDeletion::class)->active($server)));
        Server::saved(function (Server $server) {
            if ($server->wasChanged(['owner_id', 'name'])) {
                RecoveryVault::updateOwner($server);
            }
        });
        Server::deleted(function (Server $server) {
            if (Schema::hasTable('ibyte_restic_repositories')) {
                RecoveryRepository::where('server_uuid', $server->uuid)->update(['server_deleted_at' => now()]);
            }
        });
        Backup::deleted(function (Backup $backup) {
            if ($backup->backupHost->schema !== 'restic') {
                return;
            }
            DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $backup->uuid)->update(['forgotten_at' => now()]);
            if ($backup->backupHost->schema === 'restic' && $backup->is_successful && $backup->completed_at
                && ! app(RetainedDeletion::class)->active($backup->server) && ! app(RepositoryRemoval::class)->active($backup)) {
                app(Operations::class)->pruneAfterForget($backup);
            }
        });
        Backup::updated(function (Backup $backup) {
            if ($backup->backupHost->schema === 'restic') {
                Installation::assertReady();
                DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $backup->uuid)->update(['backup_name' => $backup->name, 'is_locked' => $backup->is_locked]);
            }
        });
        Backup::updating(function (Backup $backup) {
            if ($backup->backupHost->schema === 'restic') {
                Installation::assertReady();
            }
            if (! $backup->isDirty('is_locked') || $backup->backupHost->schema !== 'restic' || ! $backup->is_locked) {
                return;
            }
            $transport = app(Transport::class);
            $payload = $transport->context($backup, false) + ['operation' => 'delete', 'request_id' => $backup->uuid];
            try {
                $transport->request($backup, 'status', $payload);
            } catch (RequestException $exception) {
                if ($exception->response->status() === 404) {
                    return;
                }
                throw new RuntimeException('Cannot prove this snapshot is safe to lock; node reconciliation required.');
            }
            // Native delete holds a row lock while it waits. After an ambiguous HTTP result,
            // the daemon's durable delete job prevents a late lock from protecting a forgotten snapshot.
            throw new RuntimeException('A delete request exists for this snapshot; reconcile it before locking.');
        });

        BackupHost::saving(function (BackupHost $host) {
            if ($host->getOriginal('schema') === 'restic' && $host->isDirty(['schema', 'configuration']) && $host->backups()->exists()) {
                throw new RuntimeException('Restic host configuration is immutable while backups reference it. Create a new host for rotation.');
            }
            if ($host->schema === 'restic') {
                Compatibility::assertSupported();
                $config = $host->configuration;
                if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $config['profile'] ?? '') || ! in_array($config['backend'] ?? '', ['local', 's3'], true)) {
                    throw new RuntimeException('Select an approved node credential profile and backend.');
                }
            }
        });
        BackupHost::deleting(function (BackupHost $host) {
            if ($host->schema === 'restic' && $host->backups()->exists()) {
                throw new RuntimeException('Cannot delete a Restic host with backup records.');
            }
        });
    }
}
