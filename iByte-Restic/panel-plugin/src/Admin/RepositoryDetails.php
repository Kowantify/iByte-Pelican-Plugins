<?php

namespace IByte\Restic\Admin;

use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use IByte\Restic\Recovery\RecoveryRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RepositoryDetails
{
    public static function lastBackup(RecoveryRepository $record): ?string
    {
        return self::operations($record)->where('operation', 'create')->where('status', 'succeeded')->max('updated_at');
    }

    public static function operations(RecoveryRepository $record): Builder
    {
        return DB::table('ibyte_restic_operations')->where('host_id', $record->host_id)->where('node_id', $record->node_id)
            ->where('server_uuid', $record->server_uuid);
    }

    public static function action(): Action
    {
        return Action::make('recovery-details')->label('Details')->button()->authorize(fn () => user()?->isRootAdmin())
            ->modalHeading('Server repository')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->schema(fn (RecoveryRepository $record) => [
                Section::make('Repository identity')->columns(2)->schema([
                    TextEntry::make('server')->state($record->server_name),
                    TextEntry::make('owner')->state($record->owner_username.' / User ID '.$record->owner_id),
                    TextEntry::make('uuid')->label('Server UUID')->state($record->server_uuid),
                    TextEntry::make('source')->label('Original node / profile')->state($record->node_id.' / '.$record->profile),
                    TextEntry::make('location')->label('Repository location')->state($record->metadata['repository'] ?? 'Not captured')->columnSpanFull(),
                    TextEntry::make('state')->state(RepositoryColumns::stateLabel($record)),
                    TextEntry::make('retention')->label('Retention reason')->state($record->metadata['archive_reason'] ?? 'Active repository'),
                    TextEntry::make('last_backup')->label('Last successful backup')->state(self::lastBackup($record) ?? 'Not recorded'),
                    TextEntry::make('last_check')->label('Last successful integrity check')->state(self::operations($record)->where('operation', 'check')->where('status', 'succeeded')->max('updated_at') ?? 'Not recorded'),
                ]),
                RepeatableEntry::make('operations')->label('Recent operations')->state(self::operations($record)->latest('updated_at')->limit(8)->get(['operation', 'status', 'updated_at'])->map(fn ($row) => (array) $row)->all())
                    ->columns(3)->schema([TextEntry::make('operation'), TextEntry::make('status')->badge(), TextEntry::make('updated_at')->label('Updated')]),
            ]);
    }
}
