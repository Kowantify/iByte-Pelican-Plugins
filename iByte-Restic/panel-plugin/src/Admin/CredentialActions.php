<?php

namespace IByte\Restic\Admin;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Recovery\RepositoryExport;
use IByte\Restic\Recovery\RetainedMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CredentialActions
{
    public static function actions(): array
    {
        return [
            self::disclosure('view-restic-keys', 'View keys', 'Re-enter your current Panel password to view this repository password and recovery information. The confidential link expires in 30 seconds and can be used once. Configured S3 storage credentials are included. Older offline repositories may still have their original shared profile password.'),
            self::disclosure('view-restic-key-history', 'Key history', 'Re-enter your current Panel password to view historical credentials, including deleted-server repositories. Retired passwords can unlock older copies only when those copies retain the corresponding key wrapper. This does not rotate keys.', history: true),
            RepositoryActions::removeRecords(),
            // Pelican globally forces icon buttons. Explicit grouped views keep
            // labels visible even when the administrator prefers icon buttons.
            ActionGroup::make([
                Action::make('retained-maintenance-policy')->label('Automatic retained maintenance')->grouped()->authorize(fn () => user()?->isRootAdmin())
                    ->modalDescription('For deleted-server repositories: refresh storage daily, check integrity weekly and reclaim unreferenced data weekly through the existing Panel scheduler. No snapshots expire and no repository is deleted. Requires the original HTTPS node and a working queue. Disabled by default to avoid unexpected storage I/O.')
                    ->fillForm(fn (RecoveryRepository $record) => ['enabled' => ($record->metadata['automatic_maintenance'] ?? false) === true])
                    ->schema([Toggle::make('enabled')->label('Maintain this retained repository automatically')])
                    ->action(function (RecoveryRepository $record, array $data) {
                        RecoveryVault::authorize(user());
                        DB::transaction(function () use ($record, $data) {
                            $locked = RecoveryRepository::whereKey($record->id)->lockForUpdate()->firstOrFail();
                            $locked->update(['metadata' => array_merge($locked->metadata, ['automatic_maintenance' => (bool) $data['enabled']])]);
                        });
                        RecoveryVault::audit('retained-maintenance-policy-updated', $record->id, user()->id);
                        Notification::make()->success()->title('Automatic maintenance updated')->send();
                    }),
                Action::make('export-repository-files')->label('Export backup files')->grouped()->authorize(fn () => user()?->isRootAdmin())
                    ->disabled(fn (RecoveryRepository $record) => isset($record->metadata['repository_deleted_at']))
                    ->modalDescription('Download a snapshot as tar or compressed tar.gz, including after server deletion. The original node must be online. Export uses bounded temporary disk space on that node and streams through Panel; it preserves the repository. Recovery kits are separate confidential key exports.')
                    ->schema([
                        Select::make('backup')->label('Backup')->options(fn (RecoveryRepository $record) => DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $record->id)->whereNull('forgotten_at')->pluck('backup_uuid', 'backup_uuid')->all())->required()->searchable(),
                        Select::make('format')->options(['tar' => 'tar archive', 'tar.gz' => 'Compressed tar.gz archive'])->default('tar.gz')->required(),
                        TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required(),
                    ])->action(function (RecoveryRepository $record, array $data, $livewire) {
                        try {
                            return redirect(RepositoryExport::issue(user(), $record, $data['password'], $data['backup'], $data['format']));
                        } catch (ValidationException $exception) {
                            ActionErrors::validation($livewire, $exception);
                        } catch (Throwable $exception) {
                            report($exception);
                            Notification::make()->danger()->title('Export unavailable')->body('Verify the selected snapshot, repository generation and original node before retrying.')->send();
                        }
                    }),
                Action::make('maintain-repository')->label('Repository maintenance')->grouped()->authorize(fn () => user()?->isRootAdmin())
                    ->disabled(fn (RecoveryRepository $record) => isset($record->metadata['repository_deleted_at']))
                    ->modalDescription('Runs on the original node, including repositories retained after server deletion or transfer. Integrity checks and storage measurements preserve snapshots. Reclaim unused storage removes unreferenced chunks only; it does not choose snapshots to delete.')
                    ->schema([Select::make('operation')->label('Operation')->options(['check' => 'Check repository integrity', 'stats' => 'Refresh storage statistics', 'prune' => 'Reclaim unused storage'])->default('check')->required()])
                    ->action(function (RecoveryRepository $record, array $data) {
                        try {
                            RetainedMaintenance::queue(user(), $record, $data['operation']);
                            Notification::make()->success()->title('Repository maintenance queued')->body('You can leave this page. Saved results will appear in Stats and failures in Needs attention.')->send();
                        } catch (Throwable $exception) {
                            report($exception);
                            Notification::make()->danger()->title('Repository maintenance was not queued')->body('Review pending operations, source node configuration and protected logs before retrying.')->send();
                        }
                    }),
                self::disclosure('download-recovery-kit', 'Download recovery kit', 'Downloads confidential JSON with repository password, configured S3 credentials, owner history and snapshot mappings. Store it securely. Re-enter your current Panel password.', history: true, download: true)->grouped(),
                RepositoryActions::delete()->grouped(),
            ])->label('More')->button(),
        ];
    }

    private static function disclosure(string $name, string $label, string $description, bool $history = false, bool $download = false): Action
    {
        return Action::make($name)->label($label)->button()->authorize(fn () => user()?->isRootAdmin())
            ->modalDescription($description)
            ->schema([TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required()])
            ->action(function (RecoveryRepository $record, array $data, $livewire) use ($history, $download) {
                try {
                    return redirect(RecoveryVault::issueReveal(user(), $record, $data['password'], history: $history).($download ? '&download=1' : ''));
                } catch (ValidationException $exception) {
                    ActionErrors::validation($livewire, $exception);
                }
            });
    }
}
