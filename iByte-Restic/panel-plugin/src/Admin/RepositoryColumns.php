<?php

namespace IByte\Restic\Admin;

use App\Filament\Admin\Resources\BackupHosts\Pages\EditBackupHost;
use App\Models\BackupHost;
use Filament\Tables\Columns\TextColumn;
use IByte\Restic\Recovery\RecoveryRepository;

final class RepositoryColumns
{
    public static function identity(): array
    {
        return [
            TextColumn::make('server_name')->label('Server')
                ->description(fn (RecoveryRepository $record) => $record->owner_username.' / User ID '.$record->owner_id)
                ->searchable(['server_name', 'owner_username', 'owner_history', 'server_uuid'])->wrap()->limit(45),
            TextColumn::make('metadata.host_name')->label('Backup host')
                ->description(fn (RecoveryRepository $record) => 'Node '.$record->node_id.' / '.$record->profile)
                ->url(fn (RecoveryRepository $record) => BackupHost::whereKey($record->host_id)->exists() ? EditBackupHost::getUrl(['record' => $record->host_id], panel: 'admin') : null),
        ];
    }

    public static function state(): TextColumn
    {
        return TextColumn::make('repository_state')->label('State')->state(fn (RecoveryRepository $record) => self::stateLabel($record))->badge();
    }

    public static function stateLabel(RecoveryRepository $record): string
    {
        return isset($record->metadata['repository_deleted_at']) ? 'Deleted; keys retained'
            : (isset($record->metadata['server_destroy_request_uuid']) ? 'Destruction pending'
                : (isset($record->metadata['archived_at']) || $record->server_deleted_at ? 'Retained on source' : 'Active'));
    }

    public static function optional(): array
    {
        return [
            TextColumn::make('id')->label('Repository ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('server_uuid')->label('Server UUID')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('node_id')->label('Node ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('metadata.repository')->label('Repository location')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('metadata.generation')->label('Repository generation')->default(0)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('captured_at')->dateTime()->label('Key captured')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('server_deleted_at')->dateTime()->label('Server deleted')->placeholder('Not recorded')->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
