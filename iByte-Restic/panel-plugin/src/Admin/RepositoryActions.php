<?php

namespace IByte\Restic\Admin;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use IByte\Restic\Backups\RecordRemoval;
use IByte\Restic\Recovery\ForcedRepositoryRemoval;
use IByte\Restic\Recovery\RecoveryRepository;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class RepositoryActions
{
    public static function removeRecords(): Action
    {
        return Action::make('remove-repository-records')->label('Remove Panel records')->color('danger')->button()
            ->authorize(fn () => user()?->isRootAdmin())
            ->modalDescription(fn (RecoveryRepository $record) => 'Force removes ALL Panel backup records for '.$record->server_name.' on this source node/host, including locked and stuck records. No node connection is needed. Storage is not deleted, running node work is not cancelled, and keys/history and server restore protection remain. Use Delete entire repository to remove storage instead.')
            ->schema([TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required()])
            ->action(function (RecoveryRepository $record, array $data, $livewire) {
                try {
                    RecordRemoval::repository(user(), $record, $data['password']);
                    Notification::make()->success()->title('Panel records removed')->body('Node storage was not changed. Keys and history remain.')->send();
                } catch (ValidationException $exception) {
                    ActionErrors::validation($livewire, $exception);
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Panel records were not removed')->body($exception instanceof HttpException ? $exception->getMessage() : 'Inspect protected Panel logs; no node deletion was requested.')->send();
                }
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete-repository')->label('Delete entire repository')->color('danger')
            ->disabled(fn (RecoveryRepository $record) => isset($record->metadata['repository_deleted_at']) || $record->backend !== 'local')
            ->tooltip(fn (RecoveryRepository $record) => isset($record->metadata['repository_deleted_at']) ? 'Repository already removed; keys and history are retained.'
                : ($record->backend !== 'local' ? 'S3 repository cleanup is not implemented.'
                    : 'Permanently remove this server repository, including locked and unrecognized snapshots.'))
            ->authorize(fn () => user()?->isRootAdmin())
            ->modalDescription(fn (RecoveryRepository $record) => 'PERMANENTLY deletes the ENTIRE local repository for '.$record->server_name.': '.$record->metadata['repository'].'. Overrides backup locks, missing mappings, corrupt repositories and stale Panel status. All snapshots, including unrecognized snapshots, are destroyed. Stop external Restic writers first. Game files and escrowed keys/history are retained. A reachable updated source node is required; active addon jobs and unsafe paths are refused. Enter your current Panel password to approve this irreversible deletion.')
            ->schema([TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required()])
            ->action(function (RecoveryRepository $record, array $data, $livewire) {
                try {
                    app(ForcedRepositoryRemoval::class)->run(user(), $record, $data['password']);
                    Notification::make()->success()->title('Repository deletion confirmed')->body('Recovery keys and owner history remain. Your next backup automatically receives a fresh repository and key on this same backup host.')->send();
                } catch (ValidationException $exception) {
                    ActionErrors::validation($livewire, $exception);
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Repository deletion not confirmed')
                        ->body($exception instanceof HttpException ? $exception->getMessage() : 'No success was recorded. Preserve node state and inspect protected Panel/node logs.')->persistent()->send();
                }
            });
    }
}
