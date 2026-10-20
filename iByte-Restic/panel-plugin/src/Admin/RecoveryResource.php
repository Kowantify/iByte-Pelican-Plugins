<?php

namespace IByte\Restic\Admin;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Support\Installation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RecoveryResource extends Resource
{
    protected static ?string $model = RecoveryRepository::class;

    protected static ?string $slug = 'restic-backups';

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Key;

    public static function getNavigationLabel(): string
    {
        return 'Restic Backups';
    }

    public static function getModelLabel(): string
    {
        return 'Restic repository';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Restic repositories';
    }

    public static function canViewAny(): bool
    {
        return user()?->isRootAdmin() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        if (Installation::missing() !== []) {
            // A genuinely empty native query with the expected column names lets
            // the authorized admin page render its repair action before migration.
            $columns = ['id', 'host_id', 'node_id', 'server_uuid', 'profile', 'backend', 'owner_id', 'owner_username', 'server_name', 'metadata', 'owner_history', 'captured_at', 'server_deleted_at', 'created_at', 'updated_at'];
            $empty = DB::table('users')->selectRaw(implode(', ', array_map(fn ($column) => 'NULL AS '.$column, $columns)))->whereRaw('1 = 0');

            return parent::getEloquentQuery()->fromSub($empty, 'ibyte_restic_repositories');
        }

        return parent::getEloquentQuery()->select(['id', 'host_id', 'node_id', 'server_uuid', 'profile', 'backend', 'owner_id', 'owner_username', 'server_name', 'metadata', 'owner_history', 'captured_at', 'server_deleted_at', 'created_at', 'updated_at']);
    }

    public static function table(Table $table): Table
    {
        return $table->getLivewire()->repositoryTable($table)
            ->defaultSort('captured_at', 'desc')
            ->emptyStateHeading('No repositories to display')
            ->emptyStateDescription('Repositories appear automatically when a Restic backup is created. Configure a backup host and its linked nodes first.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecoveryRepositories::route('/'),
            'stats' => RepositoryStatistics::route('/stats'),
            'administrative' => RepositoryAdministration::route('/administrative'),
        ];
    }
}
