<?php

namespace IByte\Restic\Admin;

use App\Models\Backup;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use IByte\Restic\Backups\RecordRemoval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class StaleBackupActions
{
    public static function action(): Action
    {
        return Action::make('ibyte-restic-remove-record')->label('Remove stale Panel record')->button()->color('danger')
            ->authorize(fn () => user()?->isRootAdmin())
            ->visible(fn (?Model $record) => user()?->isRootAdmin() && (! $record instanceof Backup || $record->backupHost?->schema === 'restic'))
            ->modalDescription('Force removes the selected Panel backup record, including locked, failed or stuck records. No node connection is required and no storage is deleted. Any remaining snapshot becomes unmanaged. Recovery keys, history and server restore protection remain. This cannot cancel an already running node job.')
            ->schema([
                Select::make('backup_uuid')->label('Restic backup record')->searchable()->required(fn (?Model $record) => ! $record instanceof Backup)
                    ->hidden(fn (?Model $record) => $record instanceof Backup)
                    ->options(fn () => self::choices(''))->getSearchResultsUsing(fn (string $search) => self::choices($search))
                    ->getOptionLabelUsing(fn ($value) => self::choices((string) $value)[$value] ?? null)
                    ->helperText('Search backup name, server name or UUID. This removes metadata only.'),
                TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required(),
            ])->action(function (array $data, ?Model $record, $livewire) {
                abort_unless(user()?->isRootAdmin(), 403);
                try {
                    RecordRemoval::force(user(), $record instanceof Backup ? $record->uuid : $data['backup_uuid'], $data['password']);
                    Filament::getTenant()?->unsetRelation('backups');
                    Notification::make()->success()->title('Stale Panel record removed')->body('Node storage was not contacted or modified. Recovery keys and operation history were preserved.')->send();
                } catch (ValidationException $exception) {
                    ActionErrors::validation($livewire, $exception);
                } catch (Throwable $exception) {
                    report($exception);
                    $message = 'Review protected Panel logs. No node operation was requested.';
                    if ($exception instanceof HttpException) {
                        $message = $exception->getStatusCode() === 429 ? 'Too many password confirmations. Wait one minute, then try again.' : ($exception->getMessage() ?: $message);
                    }
                    Notification::make()->danger()->title('Record was not removed')
                        ->body($message)->persistent()->send();
                }
            });
    }

    private static function choices(string $search): array
    {
        abort_unless(user()?->isRootAdmin(), 403);
        $query = Backup::with('server')->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'));
        if ($search !== '') {
            $term = '%'.mb_substr($search, 0, 128).'%';
            $query->where(fn ($query) => $query->where('uuid', 'like', $term)->orWhere('name', 'like', $term)->orWhereHas('server', fn ($server) => $server->where('name', 'like', $term)));
        }

        return $query->latest('id')->limit(50)->get()->mapWithKeys(fn (Backup $backup) => [$backup->uuid => $backup->server?->name.' / '.$backup->name.' / '.$backup->uuid])->all();
    }
}
