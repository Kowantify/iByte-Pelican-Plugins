<?php

namespace IByte\Restic\Admin;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Storage\StorageReport;

final class RepositoryStatistics extends ResticPage
{
    protected static ?string $navigationLabel = 'Stats';

    protected array $measurements = [];

    protected function description(): string
    {
        return 'Saved automatically after backups and pruning. Measurements remain available when nodes are offline. Check each measurement date; this page does not contact nodes or measure live reachability.';
    }

    public function measurement(RecoveryRepository $record): ?array
    {
        if (! array_key_exists($record->id, $this->measurements)) {
            $row = isset($record->metadata['repository_deleted_at']) ? null : StorageReport::repository($record);
            $metrics = $row ? json_decode($row->metrics, true) : null;
            $this->measurements[$record->id] = is_array($metrics) && StorageReport::validMetrics($metrics) && isset($metrics['repository'])
                ? $metrics['repository'] + ['measured_at' => $row->updated_at] : null;
        }

        return $this->measurements[$record->id];
    }

    public static function ratio(?float $numerator, ?float $denominator): string
    {
        return $numerator !== null && $denominator !== null && $denominator > 0 ? number_format($numerator / $denominator, 2).'x' : 'Unavailable';
    }

    public function overview(): array
    {
        $totals = ['repositories' => 0, 'measured' => 0, 'physical_measured' => 0, 'files' => 0, 'logical' => 0, 'unique' => 0];
        RecoveryResource::getEloquentQuery()->chunkById(200, function ($records) use (&$totals) {
            foreach ($records as $record) {
                if (isset($record->metadata['repository_deleted_at'])) {
                    continue;
                }
                $totals['repositories']++;
                if (! ($s = $this->measurement($record))) {
                    continue;
                }
                $totals['measured']++;
                $totals['logical'] += $s['logical_bytes'];
                $totals['unique'] += $s['total_uncompressed_size'];
                if (isset($s['repository_file_bytes'])) {
                    $totals['physical_measured']++;
                    $totals['files'] += $s['repository_file_bytes'];
                }
            }
        });

        return $totals;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Storage overview')->description('Totals include the latest saved measurement for each current repository generation. Removed repositories are excluded. Missing measurements are never counted as zero usage.')
                ->columns(3)->schema(function () {
                    $s = $this->overview();

                    return [
                        Stat::make('Repository files measured', $s['physical_measured'] ? convert_bytes_to_readable($s['files']) : 'Unavailable')
                            ->description($s['physical_measured'].' of '.$s['repositories'].' repositories have a saved file measurement'),
                        Stat::make('Logical restore data', $s['measured'] ? convert_bytes_to_readable($s['logical']) : 'Unavailable')
                            ->description($s['measured'].' of '.$s['repositories'].' repositories measured; includes all snapshots'),
                        Stat::make('Deduplication estimate', self::ratio($s['measured'] ? $s['logical'] : null, $s['unique']))
                            ->description('Logical data / unique uncompressed referenced blobs'),
                    ];
                }),
            EmbeddedTable::make(),
            Section::make('Understanding storage')->collapsible()->columns(2)->schema([
                TextEntry::make('physical_definition')->label('Repository files')->state('Saved total file lengths, including metadata and unpruned packs. Allocated disk blocks and cloud billing can differ. Remote physical usage may be unavailable.'),
                TextEntry::make('dedup_definition')->label('Deduplication estimate')->state('Total logical restore bytes across snapshots divided by unique uncompressed referenced blob bytes. A 2x estimate means those bytes are referenced twice on average.'),
                TextEntry::make('compression_definition')->label('Compression')->state('Unique uncompressed blob bytes divided by compressed blob bytes. A 2x ratio means those blobs occupy about half their uncompressed size. Metadata and unused packs are additional.'),
                TextEntry::make('snapshot_definition')->label('Size on native backup lists')->state('Added compressed data records new bytes written when that backup was created. Shared data cannot be assigned exclusively to one snapshot. Deleting a snapshot does not necessarily free its displayed bytes; pruning reclaims only unreferenced data.'),
            ]),
        ]);
    }

    public function repositoryTable(Table $table): Table
    {
        return $table->heading('Storage by server')->columns([
            ...RepositoryColumns::identity(),
            TextColumn::make('storage')->label('Repository files')
                ->state(fn (RecoveryRepository $record) => isset($record->metadata['repository_deleted_at']) ? 'Removed'
                    : (($s = $this->measurement($record)) && isset($s['repository_file_bytes']) ? convert_bytes_to_readable($s['repository_file_bytes']) : 'Unavailable')),
            TextColumn::make('logical')->label('Logical restores')->state(fn (RecoveryRepository $record) => ($s = $this->measurement($record)) ? convert_bytes_to_readable($s['logical_bytes']) : 'Unavailable'),
            TextColumn::make('dedup')->label('Dedup estimate')->state(fn (RecoveryRepository $record) => ($s = $this->measurement($record)) ? self::ratio($s['logical_bytes'], $s['total_uncompressed_size']) : 'Unavailable'),
            TextColumn::make('compression')->label('Compression')->state(fn (RecoveryRepository $record) => ($s = $this->measurement($record)) ? self::ratio($s['total_uncompressed_size'], $s['total_size']) : 'Unavailable')
                ->description(fn (RecoveryRepository $record) => ($s = $this->measurement($record)) && $s['total_uncompressed_size'] > 0 ? number_format((1 - $s['total_size'] / $s['total_uncompressed_size']) * 100, 1).'% blob savings' : null),
            TextColumn::make('measured_at')->label('Measured at')->state(fn (RecoveryRepository $record) => $this->measurement($record)['measured_at'] ?? null)->dateTime()->placeholder('Pending / unavailable'),
            TextColumn::make('snapshots')->label('Measured snapshots')->state(fn (RecoveryRepository $record) => $this->measurement($record)['snapshots_count'] ?? null)->placeholder('Unavailable')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('compressed')->label('Referenced compressed blobs')->state(fn (RecoveryRepository $record) => ($s = $this->measurement($record)) ? convert_bytes_to_readable($s['total_size']) : 'Unavailable')->toggleable(isToggledHiddenByDefault: true),
            ...RepositoryColumns::optional(),
        ])->recordActions([]);
    }
}
