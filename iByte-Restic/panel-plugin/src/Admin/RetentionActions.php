<?php

namespace IByte\Restic\Admin;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Backups\BackupResource;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Backups\DeleteBackupService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use IByte\Restic\Backups\Retention;
use IByte\Restic\Storage\StorageReport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class RetentionActions
{
    public static function register(): void
    {
        BackupResource::modifyTable(function (Table $table): Table {
            $delete = $table->getFlatRecordActions()['exclude_delete'] ?? null;
            if ($delete && ($original = $delete->getActionFunction())) {
                $delete->action(function (Backup $backup, DeleteBackupService $deleteBackupService) use ($delete, $original) {
                    try {
                        return $delete->evaluate($original, ['backup' => $backup, 'deleteBackupService' => $deleteBackupService]);
                    } catch (Throwable $exception) {
                        if ($backup->backupHost->schema !== 'restic') {
                            throw $exception;
                        }
                        report($exception);
                        Notification::make()->danger()->title('Backup deletion could not be confirmed')
                            ->body($exception instanceof HttpException ? $exception->getMessage() : 'Backup metadata is retained until deletion is verified. If it was removed outside Panel, a root administrator can use Restic Backups > Administrative > Remove stale Panel record. Otherwise inspect the protected Panel/node logs.')->persistent()->send();
                    }
                });
            }
            $server = Filament::getTenant();
            if (! $server instanceof Server || ! Retention::applies($server)) {
                return $table;
            }
            $actions = $table->getToolbarActions();
            foreach ($actions as $action) {
                if ($action->getName() === 'create') {
                    $action->disabled(fn () => $server->backup_limit <= 0 || ($server->backups()->nonFailed()->count() >= $server->backup_limit
                        && (! user()?->can(SubuserPermission::BackupDelete, $server) || ! Retention::candidate($server, reserve: true))))
                        ->color('primary')->tooltip(fn () => $server->backup_limit <= 0 ? 'This server has no backup slots.'
                            : ($server->backups()->nonFailed()->count() >= $server->backup_limit
                                ? (Retention::candidate($server, reserve: true) ? 'Creating now permanently replaces the oldest eligible unlocked backup.' : 'No completed unlocked backup can be replaced. Unlock one or wait for pending work.')
                                : 'Create backup.'));
                }
            }
            $actions[] = Action::make('ibyte-restic-storage')->label('Storage statistics')->button()
                ->authorize(fn () => user()?->can(SubuserPermission::BackupRead, $server))
                ->modalDescription(fn () => StorageReport::serverSummary($server))->modalSubmitAction(false);
            $actions[] = Action::make('ibyte-restic-retention')->label('Backup retention')->button()
                ->authorize(fn () => user()?->can(SubuserPermission::BackupDelete, $server) && user()?->can(SubuserPermission::BackupCreate, $server))
                ->modalDescription(function () use ($server) {
                    $policy = Retention::policy($server);

                    return 'Applies after successful manual and built-in scheduled backups. Locked and pending backups consume quota and are never removed. Daily/weekly reserve the newest copy from distinct completed days/weeks; remaining slots keep one per hour. Saving applies at the next successful backup; reducing retention permanently removes eligible snapshots. Native Schedules controls when backups run. Last retention: '.($policy['last_run_at'] ?? 'not run').'. '.($policy['last_error'] ?? '');
                })
                ->fillForm(fn () => Retention::policy($server))
                ->schema([
                    Toggle::make('enabled')->label('Roll up hourly backups into daily and weekly copies')->default(false),
                    TextInput::make('daily')->label('Completed daily buckets to retain')->integer()->minValue(0)->maxValue(1000)->required(),
                    TextInput::make('weekly')->label('Completed weekly buckets to retain')->integer()->minValue(0)->maxValue(1000)->required(),
                    TextInput::make('timezone')->label('Retention timezone')->required()->rules(['timezone'])->helperText('For example UTC or America/Chicago. Weeks start Monday.'),
                ])->action(function (array $data) use ($server) {
                    Retention::save(user(), $server, $data);
                    Notification::make()->success()->title('Retention saved; applies after the next successful backup')->send();
                });

            return $table->toolbarActions($actions);
        });
    }
}
