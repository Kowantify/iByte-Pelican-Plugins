<?php

namespace IByte\Restic;

use App\Extensions\BackupAdapter\BackupAdapterSchemaInterface;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use IByte\Restic\Backups\DownloadController;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Integration\BridgeRepository;
use IByte\Restic\Integration\LifecycleGuard;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RepositoryRemoval;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\ServerDestruction;
use IByte\Restic\Support\Installation;
use IByte\Restic\Support\OperationDiagnostics;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResticSchema implements BackupAdapterSchemaInterface
{
    public const array DEFAULT_CONFIGURATION = [
        'backend' => 'local',
        'profile' => 'default',
        'staging_enabled' => false,
        'restore_fixture_enabled' => false,
    ];

    public function __construct(private Operations $operations, private Transport $transport) {}

    public function getId(): string
    {
        return 'restic';
    }

    public function getName(): string
    {
        return 'Restic';
    }

    public function getConfigurationForm(): array
    {
        return [
            Select::make('configuration.backend')->label('Backend')->options(['local' => 'Local filesystem', 's3' => 'S3 over HTTPS'])
                ->default(self::DEFAULT_CONFIGURATION['backend'])->required(),
            TextInput::make('configuration.profile')->label('Node credential profile reference')->required()->maxLength(64)
                ->default(self::DEFAULT_CONFIGURATION['profile'])
                ->regex('/^[a-z0-9][a-z0-9_-]{0,63}$/D')
                ->helperText('The local node installer creates profile default. Use another name only if it was provisioned on this node. Passwords and storage credentials stay in mode 0600 node files.'),
            // Native forms replace the complete configuration array. Preserve
            // server-side safety settings without editable controls here; only
            // Administrative registers the action that can change them.
            Hidden::make('configuration.staging_enabled')->default(false)
                ->dehydrateStateUsing(fn (?BackupHost $record) => (bool) ($record?->configuration['staging_enabled'] ?? false)),
            Hidden::make('configuration.restore_fixture_enabled')->default(false)
                ->dehydrateStateUsing(fn (?BackupHost $record) => (bool) ($record?->configuration['restore_fixture_enabled'] ?? false)),
        ];
    }

    public function createBackup(Backup $backup): void
    {
        LifecycleGuard::assertNotReserved($backup->server);
        // The UI caller does not take the API controller's parent-row lock. Enforce quota again
        // inside the native creation transaction before any node work can be dispatched.
        $backup->server->newQuery()->whereKey($backup->server_id)->lockForUpdate()->value('id');
        if ($backup->server->backups()->nonFailed()->count() > $backup->server->backup_limit) {
            throw new RuntimeException('The native backup quota has been reached.');
        }
        $this->operations->queue($backup, 'create', $backup->uuid);
    }

    public function deleteBackup(Backup $backup): void
    {
        Installation::assertReady();
        if (app(ServerDestruction::class)->active($backup->server)) {
            // The committed repository-wide cleanup plan supersedes individual
            // snapshot deletion, including locked/stuck native backup rows.
            return;
        }
        // Only the explicitly password/UUID-confirmed retained-deletion service
        // activates this scope, after proving key + snapshot escrow coverage.
        if (app(RetainedDeletion::class)->preserves($backup)) {
            return;
        }
        if (! app(RetainedDeletion::class)->active($backup->server) && ! app(RepositoryRemoval::class)->active($backup)) {
            LifecycleGuard::assertNotReserved($backup->server);
        }
        $current = Backup::whereKey($backup->id)->lockForUpdate()->firstOrFail();
        if ($current->is_locked) {
            throw new RuntimeException('Unlock this backup through native permissions before deleting.');
        }
        if (! $backup->completed_at) {
            throw new RuntimeException('Cannot delete an in-progress Restic backup.');
        }
        $context = $this->transport->context($backup);
        $payload = $context + ['operation' => 'delete', 'request_id' => $backup->uuid];
        $state = $this->transport->request($backup, 'jobs', $payload);
        // Native delete is synchronous. Never let Panel delete successful metadata on mere acceptance.
        $deadline = microtime(true) + 20;
        while (($state['status'] ?? '') === 'running' && microtime(true) < $deadline) {
            usleep(250000);
            $state = $this->transport->request($backup, 'status', $payload);
        }
        foreach (['host', 'node', 'profile', 'server', 'backup', 'operation', 'request_id'] as $key) {
            if (($state[$key] ?? null) !== $payload[$key]) {
                throw new RuntimeException('Deletion identity cannot be verified; metadata retained for reconciliation.');
            }
        }
        if (($state['generation'] ?? 0) !== ($payload['generation'] ?? 0)) {
            throw new RuntimeException('Deletion generation cannot be verified; metadata retained.');
        }
        if (($state['status'] ?? '') !== 'succeeded') {
            throw new ConflictHttpException('Snapshot deletion was not confirmed. '.OperationDiagnostics::nodeFailure($state['error'] ?? null).' The Panel record was preserved. To discard everything when deleting the server, choose Force delete server and destroy all Restic repositories.');
        }
    }

    public function getDownloadLink(Backup $backup, User $user): string
    {
        // Native Filament evaluates download URLs while rendering every row.
        // Signing a Panel URL must not perform readiness/mapping validation:
        // DownloadController and BridgeRepository recheck before any node work.
        // Native restore also requests a download URL. It will be unused by BridgeRepository.
        return URL::temporarySignedRoute('ibyte-restic.download', now()->addMinutes(2), ['backup' => $backup->uuid, 'user' => $user->id, 'nonce' => Str::random(32)]);
    }
}
