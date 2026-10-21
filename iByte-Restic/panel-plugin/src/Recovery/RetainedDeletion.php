<?php

namespace IByte\Restic\Recovery;

use App\Enums\HeaderActionPosition;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Filament\Admin\Resources\Servers\Pages\ViewServer;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Services\Backups\DeleteBackupService;
use App\Services\Servers\ServerDeletionService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use IByte\Restic\Integration\LifecycleGuard;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Support\Compatibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RetainedDeletion
{
    private ?string $server = null;

    private ?string $retainedBackup = null;

    public function preserves(Backup $backup): bool
    {
        return $this->active($backup->server) && $this->retainedBackup === $backup->uuid;
    }

    /**
     * Automatic latest-only retention for an Application-API deletion
     * (external billing systems). Marks the same scoped state the confirmed
     * UI action sets; no password is involved because the actor authenticated
     * with a root-admin Application API key upstream.
     */
    public function retainForApiDeletion(Server $server, Backup $retained, \App\Models\User $actor): void
    {
        RecoveryVault::authorize($actor);
        $this->server = $server->uuid;
        $this->retainedBackup = $retained->uuid;
        RecoveryVault::audit('api-delete-automatic-retention', actor: $actor->id);
    }

    /** Clear the API-retention scope after the deletion finished or failed. */
    public function clearApiRetention(Server $server): void
    {
        if ($this->active($server)) {
            $this->server = null;
            $this->retainedBackup = null;
        }
    }

    public function detachForTransfer(Server $server): void
    {
        if (! $this->active($server)) {
            return;
        }
        $backup = $server->backups()->where('uuid', $this->retainedBackup)->firstOrFail();
        $backup->deleteQuietly();
        // Runs in the native transfer-creation transaction. Its row and our
        // archive detachment commit together BEFORE native notify() starts I/O.
        DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('operation', 'lifecycle')->where('status', 'reserved')->update(['status' => 'succeeded', 'updated_at' => now()]);
        DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('operation', 'final-backup')->where('backup_uuid', $backup->uuid)->where('status', 'prepared')->update(['status' => 'succeeded', 'updated_at' => now()]);
    }

    public function active(Server $server): bool
    {
        return $this->server === $server->uuid;
    }

    public static function register(): void
    {
        foreach ([ViewServer::class, EditServer::class] as $page) {
            $page::registerCustomHeaderActions(HeaderActionPosition::After,
                Action::make('ibyte-restic-retain-delete')->label('Keep latest Restic backup and delete server')->button()->color('danger')
                    ->visible(fn (Server $record) => $record->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->exists())
                    ->authorize(fn () => user()?->isRootAdmin())
                    ->modalDescription(self::warning())
                    ->schema([TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required()])
                    ->action(function (Server $record, array $data) {
                        if (! app(self::class)->run(user(), $record, $data['password'], $record->uuid)) {
                            return null;
                        }

                        return redirect('/admin/servers');
                    })
            );
        }
    }

    public function run(User $user, Server $server, string $password, string $confirmation): bool
    {
        if (! FinalBackupPreparation::ready($user, $server, $password, $confirmation, 'delete')) {
            return false;
        }
        $this->transition($user, $server, $password, $confirmation, 'delete', fn (Server $locked) => app(ServerDeletionService::class)->handle($locked));

        return true;
    }

    public static function warning(): string
    {
        return 'Keep mode prepares a fresh locked final backup. After it shows Successful, confirm again within 15 minutes. Only that final snapshot and its key/owner history remain on the source node; Restic backups will NOT be transferred. A free backup slot is required and older locked copies must be unlocked first. Preparation never evicts existing recovery points. Stop/flush the game first when required for consistency. Confirm with your current Panel password; no typed UUID is required.';
    }

    public function transition(User $user, Server $server, string $password, string $confirmation, string $operation, callable $native, ?int $destination = null): mixed
    {
        if (! in_array($operation, ['delete', 'transfer'], true)) {
            throw new RuntimeException('Unsupported server lifecycle operation.');
        }
        RecoveryVault::confirm($user, $password);
        if (! hash_equals($server->uuid, $confirmation)) {
            throw new RuntimeException('Server UUID confirmation does not match.');
        }
        Compatibility::assertSupported();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Server lifecycle confirmation requires committed recovery escrow outside any surrounding transaction.');
        }
        LifecycleGuard::assertClear($server, true);
        if ($server->transfer) {
            throw new RuntimeException('Complete or cancel the existing native transfer before deleting or transferring this server.');
        }
        $latest = $server->backups()->where('is_successful', true)->whereNotNull('completed_at')->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->orderByDesc('created_at')->orderByDesc('id')->first();
        if (! $latest) {
            throw new RuntimeException('No verified successful Restic backup can be retained. Reconcile stale records first.');
        }
        if ($server->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->where('uuid', '!=', $latest->uuid)->where('is_locked', true)->exists()) {
            throw new RuntimeException('Unlock older locked Restic backups before deletion or transfer. The latest successful backup will be retained.');
        }
        if ($server->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->whereNull('completed_at')->exists()) {
            throw new RuntimeException('An unfinished Restic backup must be reconciled before deletion or transfer.');
        }
        foreach ($server->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->get()->groupBy('backup_host_id') as $backups) {
            $repo = RecoveryVault::capture($backups->first());
            RecoveryVault::finalize($backups->first());
            foreach ($backups as $backup) {
                if ($backup->is_successful && ! DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->where('backup_uuid', $backup->uuid)->whereNull('forgotten_at')->exists()) {
                    throw new RuntimeException('A successful backup lacks a durable recovery snapshot mapping. Preserve the server and reconcile first.');
                }
            }
            RecoveryVault::audit('retain-'.$operation.'-authorized', $repo->id, $user->id);
        }
        if ($this->server !== null) {
            throw new RuntimeException('Another retained deletion is active.');
        }

        // Escrow above is committed BEFORE any external deletion, so a later
        // native database failure cannot roll the recovery credentials back.
        $reservation = (string) Str::uuid();
        $result = DB::transaction(function () use ($server, $user, $latest, $operation, $native, $destination, $reservation): mixed {
            $locked = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            LifecycleGuard::assertClear($locked, true);
            $currentLatest = $locked->backups()->where('is_successful', true)->whereNotNull('completed_at')->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($currentLatest?->uuid !== $latest->uuid) {
                throw new RuntimeException('The latest backup changed during confirmation; retry.');
            }
            $backups = $locked->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->lockForUpdate()->get();
            if (! $backups->contains('uuid', $latest->uuid)) {
                throw new RuntimeException('The retained backup changed during preflight. Retry.');
            }
            foreach ($backups as $backup) {
                if (! $backup->completed_at || ($backup->uuid !== $latest->uuid && $backup->is_locked)) {
                    throw new RuntimeException('Backup lock or completion state changed; retry after reconciliation.');
                }
            }
            foreach ($locked->backups()->where('is_successful', true)->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->get() as $backup) {
                $repo = RecoveryRepository::where('server_uuid', $locked->uuid)->where('node_id', $locked->node_id)->where('host_id', $backup->backup_host_id)->where('profile', $backup->backupHost->configuration['profile'])->first();
                if (! $repo || ! DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->where('backup_uuid', $backup->uuid)->whereNull('forgotten_at')->exists()) {
                    throw new RuntimeException('Recovery coverage changed during preflight. Server deletion refused.');
                }
            }
            // Prove presence while the native backup rows are locked, so a
            // simultaneous authorized deletion cannot invalidate this proof.
            $transport = app(Transport::class);
            $identity = $transport->context($latest, false);
            $proof = $transport->request($latest, 'snapshot-proof', $identity);
            $snapshot = DB::table('ibyte_restic_recovery_snapshots')->where('backup_uuid', $latest->uuid)->whereNull('forgotten_at')->value('snapshot_id');
            foreach (['host', 'node', 'profile', 'server', 'backup'] as $key) {
                if (($proof[$key] ?? null) !== $identity[$key]) {
                    throw new RuntimeException('Retained snapshot identity could not be verified. Server preserved.');
                }
            }
            if (($proof['generation'] ?? 0) !== ($identity['generation'] ?? 0) || ! $snapshot || ($proof['snapshot'] ?? null) !== $snapshot || ($proof['deleted'] ?? true) !== false) {
                throw new RuntimeException('Latest retained snapshot is missing or unverified. Reconcile it before server deletion or transfer.');
            }
            $this->server = $locked->uuid;
            $this->retainedBackup = $latest->uuid;
            try {
                DB::table('ibyte_restic_operations')->where('server_uuid', $locked->uuid)->where('operation', 'lifecycle')->where('status', 'reserved')->update(['status' => 'dismissed', 'updated_at' => now()]);
                DB::table('ibyte_restic_operations')->insert(['request_uuid' => $reservation, 'backup_uuid' => $latest->uuid, 'server_uuid' => $locked->uuid, 'node_id' => $locked->node_id, 'host_id' => $latest->backup_host_id, 'operation' => 'lifecycle', 'status' => 'reserved', 'payload' => json_encode(['action' => $operation, 'destination' => $destination], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                // Forget older snapshots while the source daemon still knows this
                // server. Native deletion contacts Wings BEFORE adapter callbacks.
                foreach ($backups as $backup) {
                    if ($backup->uuid !== $latest->uuid) {
                        app(DeleteBackupService::class)->handle($backup);
                    }
                }
                foreach (RecoveryRepository::where('server_uuid', $locked->uuid)->where('node_id', $locked->node_id)->get() as $repo) {
                    $metadata = $repo->metadata;
                    $metadata += ['archived_at' => now()->toIso8601String(), 'archive_reason' => $operation, 'destination_node_id' => $destination, 'retained_backup_uuid' => $latest->uuid];
                    $repo->update(['metadata' => $metadata]);
                    RecoveryVault::audit('server-'.$operation.'-retained', $repo->id, $user->id);
                }
                $locked->unsetRelation('backups');

                if ($operation === 'transfer') {
                    return $locked;
                }
                $response = $native($locked);
                if (Server::whereKey($locked->id)->exists()) {
                    throw new RuntimeException('Native deletion did not complete. Source snapshot preserved; review the native daemon failure before retrying.');
                }
                DB::table('ibyte_restic_operations')->where('server_uuid', $locked->uuid)->where('operation', 'final-backup')->where('backup_uuid', $latest->uuid)->where('status', 'prepared')->update(['status' => 'succeeded', 'updated_at' => now()]);
                DB::table('ibyte_restic_operations')->where('request_uuid', $reservation)->update(['status' => 'succeeded', 'updated_at' => now()]);

                return $response;
            } finally {
                $this->server = null;
                $this->retainedBackup = null;
            }
        });
        if ($operation !== 'transfer') {
            return $result;
        }
        // Do not wrap native transfer notification in our SQL transaction:
        // daemon callbacks must see its committed native ServerTransfer row.
        $this->server = $result->uuid;
        $this->retainedBackup = $latest->uuid;
        try {
            return $native($result);
        } finally {
            DB::table('ibyte_restic_operations')->where('request_uuid', $reservation)->where('status', 'reserved')->update(['status' => 'failed', 'error' => 'Native transfer did not start; latest source snapshot retained.', 'updated_at' => now()]);
            $this->server = null;
            $this->retainedBackup = null;
        }
    }
}
