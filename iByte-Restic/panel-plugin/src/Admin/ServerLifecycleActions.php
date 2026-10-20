<?php

namespace IByte\Restic\Admin;

use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Filament\Admin\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use IByte\Restic\Recovery\FinalBackupPreparation;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\ServerDestruction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

// Exact-version Filament compatibility integration. The native pages, forms,
// authorization and handlers remain in place; no Panel source is modified.
final class ServerLifecycleActions
{
    private static function server(): ?Server
    {
        $page = Livewire::current();
        if (! $page instanceof EditServer && ! $page instanceof ViewServer) {
            return null;
        }
        try {
            $server = $page->getRecord();

            return $server instanceof Server && (FinalBackupPreparation::applies($server) || $server->backups()->whereHas('backupHost', fn ($q) => $q->where('schema', 'restic'))->exists() || RecoveryRepository::where('server_uuid', $server->uuid)->exists()) ? $server : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function fields(bool $deleting = true): array
    {
        return [
            ...($deleting ? [Select::make('restic_delete_mode')->label('Restic backups')->options([
                'retain' => 'Keep a fresh final backup',
                'destroy' => 'Force delete server and destroy all Restic repositories',
            ])->default('retain')->required()->live()->helperText('Keep requires a completed final backup and a second confirmation. Destroy skips the final backup, overrides snapshot locks and uses native force deletion. Offline repository cleanup remains pending until verified; all snapshots will be permanently removed.')] : []),
            TextInput::make('restic_password')->label('Current Panel password')->password()->autocomplete('current-password')->required(),
        ];
    }

    public static function register(): void
    {
        Action::configureUsing(function (Action $action) {
            if (! in_array($action->getName(), ['Delete', 'exclude_force_delete'], true) || ! self::server()) {
                return;
            }
            $action->modalDescription('Choose whether to retain a final backup or permanently destroy the repositories. Your current Panel password authorizes the selected action for this server.')->schema(self::fields());
            // The native handler is assigned after Action::make(). Capture it
            // only at execution, after native authorization and form validation.
            $action->before(function (Action $action) {
                self::wrap($action, 'delete');
            });
        });
        Actions::configureUsing(function (Actions $component) {
            if (! self::server()) {
                return;
            }
            foreach ($component->getDefaultChildComponents() as $action) {
                if ($action instanceof Action && $action->getName() === 'exclude_transfer') {
                    // Actions::make receives already configured native children,
                    // including the native node/allocation/backups schema.
                    $schema = $action->getSchema(Schema::make(Livewire::current()));
                    $action->schema(array_merge($schema->getComponents(withHidden: true), self::fields(false)))
                        ->modalDescription(RetainedDeletion::warning());
                    self::wrap($action, 'transfer');
                }
            }
        });
    }

    private static function wrap(Action $action, string $operation): void
    {
        $original = $action->getActionFunction();
        $action->action(function (array $data, $livewire) use ($action, $original, $operation) {
            $server = self::server();
            abort_unless($server, 409);

            try {
                if ($operation === 'delete' && ($data['restic_delete_mode'] ?? 'retain') === 'destroy') {
                    app(ServerDestruction::class)->run(user(), $server, $data['restic_password']);
                    Notification::make()->warning()->title('Server deleted; repository destruction queued')
                        ->body('No final backup will be retained. Original repositories are permanently removed by the queue; offline or busy nodes remain pending in Restic Backups until deletion is verified. Keys and audit history remain.')->persistent()->send();

                    return redirect('/admin/servers');
                }
                if (! FinalBackupPreparation::ready(user(), $server, $data['restic_password'], $server->uuid, $operation, isset($data['node_id']) ? (int) $data['node_id'] : null)) {
                    return null;
                }

                return app(RetainedDeletion::class)->transition(user(), $server,
                    $data['restic_password'], $server->uuid, $operation,
                    fn (Server $locked) => $action->evaluate($original, ['server' => $locked, 'record' => $locked, 'data' => $data]),
                    isset($data['node_id']) ? (int) $data['node_id'] : null);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
                if (isset($errors['password'])) {
                    $errors['restic_password'] = $errors['password'];
                    unset($errors['password']);
                }
                ActionErrors::validation($livewire, ValidationException::withMessages($errors));
            } catch (Throwable $exception) {
                report($exception);
                Notification::make()->danger()->title('Server transition was not confirmed')
                    ->body($exception instanceof HttpException ? $exception->getMessage() : 'Review the final backup, existing locks and protected Panel/node logs before retrying. No successful transition was recorded.')->persistent()->send();
            }
        });
    }
}
