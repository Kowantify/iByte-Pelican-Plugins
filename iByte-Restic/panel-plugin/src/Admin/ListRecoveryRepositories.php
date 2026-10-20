<?php

namespace IByte\Restic\Admin;

use App\Filament\Admin\Resources\BackupHosts\Pages\ListBackupHosts;
use App\Filament\Admin\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use IByte\Restic\Recovery\FinalBackupPreparation;
use IByte\Restic\Recovery\RecoveryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListRecoveryRepositories extends ResticPage
{
    protected static ?string $navigationLabel = 'Servers';

    protected function description(): string
    {
        return 'Server repositories and their original owners. Backups and schedules use the native server pages. Source repositories remain here after a server is transferred or deleted.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('backup-hosts')->label('Backup hosts')->button()->url(fn () => ListBackupHosts::getUrl(panel: 'admin'))];
    }

    public function repositoryTable(Table $table): Table
    {
        return $table->heading('Server repositories')->columns([
            ...RepositoryColumns::identity(),
            TextColumn::make('retained_snapshots')->label('Snapshots')->state(fn (RecoveryRepository $record) => DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $record->id)->whereNull('forgotten_at')->count()),
            RepositoryColumns::state(),
            TextColumn::make('last_backup')->label('Last successful backup')->state(fn (RecoveryRepository $record) => RepositoryDetails::lastBackup($record))->dateTime()->placeholder('Not recorded'),
            ...RepositoryColumns::optional(),
        ])->recordActions([
            RepositoryDetails::action(),
            Action::make('clear-final-preparation')->label('Clear final-backup preparation')->button()->color('warning')->authorize(fn () => user()?->isRootAdmin())
                ->visible(fn (RecoveryRepository $record) => Server::where('uuid', $record->server_uuid)->where('node_id', $record->node_id)->exists() && DB::table('ibyte_restic_operations')->where('server_uuid', $record->server_uuid)->where('operation', 'final-backup')->where('status', 'prepared')->exists())
                ->modalDescription('Clears an obsolete delete/transfer preparation. It does not cancel a running backup, unlock it, delete files or change the server. After reviewing backups, confirm the native delete or transfer action to prepare a fresh final backup.')
                ->schema([TextInput::make('password')->label('Current Panel password')->password()->autocomplete('current-password')->required()])
                ->action(function (RecoveryRepository $record, array $data, $livewire) {
                    try {
                        $server = Server::where('uuid', $record->server_uuid)->where('node_id', $record->node_id)->firstOrFail();
                        FinalBackupPreparation::cancel(user(), $server, $data['password']);
                        Notification::make()->success()->title('Preparation cleared; backups preserved')->send();
                    } catch (ValidationException $exception) {
                        ActionErrors::validation($livewire, $exception);
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('Preparation could not be cleared')->body('Wait for pending preparation to finish, then refresh this server and retry. No backup or server data was removed.')->send();
                    }
                }),
            Action::make('open-server')->label('Open server')->button()
                ->visible(fn (RecoveryRepository $record) => Server::where('uuid', $record->server_uuid)->exists())
                ->url(fn (RecoveryRepository $record) => ($server = Server::where('uuid', $record->server_uuid)->first()) ? ViewServer::getUrl(['record' => $server], panel: 'admin') : null),
        ]);
    }
}
