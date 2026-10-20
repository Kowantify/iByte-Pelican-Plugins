<?php

namespace IByte\Restic\Admin;

use App\Models\BackupHost;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class HostActions
{
    private static function hosts(): Select
    {
        return Select::make('host_id')->label('Restic backup host')->required()->searchable()
            ->options(fn () => BackupHost::where('schema', 'restic')->get()->filter(fn ($host) => user()?->can('update', $host))->pluck('name', 'id')->all());
    }

    public static function settings(): Action
    {
        return Action::make('host-settings')->label('Preview settings')->button()->authorize(fn () => user()?->isRootAdmin())
            ->modalDescription('This release is a preview. Production restore is blocked by unsupported dispatch and incomplete file writer fencing. Settings remain immutable while native backups reference a host. Backend, credential profile and node links stay in Backup Hosts.')
            ->schema([
                self::hosts()->live()->afterStateUpdated(function ($state, Set $set) {
                    $host = BackupHost::where('schema', 'restic')->find($state);
                    $set('staging_enabled', (bool) ($host?->configuration['staging_enabled'] ?? false));
                    $set('restore_fixture_enabled', (bool) ($host?->configuration['restore_fixture_enabled'] ?? false));
                }),
                Toggle::make('staging_enabled')->label('Enable preview on disposable staging servers')->default(false),
                Toggle::make('restore_fixture_enabled')->label('Allow restore tests in an isolated disposable fixture')->default(false)
                    ->helperText('Use only with no other writers. Native Wings does not fence every upload or file write. Leave disabled outside isolated restore tests.'),
            ])->action(function (array $data, $livewire) {
                try {
                    DB::transaction(function () use ($data) {
                        $host = BackupHost::where('schema', 'restic')->lockForUpdate()->findOrFail($data['host_id']);
                        abort_unless(user()?->isRootAdmin() && user()?->can('update', $host), 403);
                        $config = array_merge($host->configuration, [
                            'staging_enabled' => (bool) $data['staging_enabled'],
                            'restore_fixture_enabled' => (bool) $data['restore_fixture_enabled'],
                        ]);
                        if ($config !== $host->configuration && $host->backups()->exists()) {
                            throw ValidationException::withMessages(['host_id' => 'Settings are locked while backups reference this host. Remove the backup records first or configure another host.']);
                        }
                        $host->update(['configuration' => $config]);
                    });
                    Notification::make()->success()->title('Preview settings saved')->send();
                } catch (ValidationException $exception) {
                    ActionErrors::validation($livewire, $exception);
                } catch (HttpException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Preview settings were not saved')
                        ->body('Check protected Panel logs and compatibility diagnostics before retrying.')->send();
                }
            });
    }

    public static function maintenance(): Action
    {
        return Action::make('host-maintenance')->label('Repository maintenance')->button()->authorize(fn () => user()?->isRootAdmin())
            ->disabled(fn () => Installation::missing() !== [])
            ->modalDescription('Queues work for the latest successful server repositories on linked nodes. Integrity checks verify metadata; reclaiming storage removes only unreferenced chunks and never chooses snapshots to forget. Work may consume significant I/O. One batch per host every five minutes. Statistics are normally captured automatically.')
            ->schema([
                self::hosts(),
                Select::make('operation')->label('Operation')->options(['check' => 'Check repository integrity', 'prune' => 'Reclaim unreferenced storage', 'stats' => 'Refresh saved storage measurements'])->default('check')->required(),
            ])->action(function (array $data) {
                // Re-query and authorize: the browser's selected host is untrusted.
                abort_unless(user()?->isRootAdmin(), 403);
                $host = BackupHost::where('schema', 'restic')->findOrFail($data['host_id']);
                try {
                    $count = HealthActions::queue(user(), $host, $data['operation']);
                    Notification::make()->success()->title($count ? 'Maintenance queued' : 'No eligible repositories')
                        ->body($count ? $count.' repository operations queued.' : 'This host has no successful server backups on its linked nodes.')->send();
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Maintenance was not fully queued')->body('Inspect protected Panel logs. Check host links, compatibility and recent maintenance before retrying.')->send();
                }
            });
    }
}
