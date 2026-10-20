<?php

namespace IByte\Restic\Storage;

use App\Filament\Components\Tables\Columns\BytesColumn;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Support\Installation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class StorageReport
{
    public static function validStorage(mixed $s): bool
    {
        return is_array($s) && collect(['total_bytes_processed', 'data_added', 'data_added_packed'])
            ->every(fn ($key) => is_int($s[$key] ?? null) && $s[$key] >= 0);
    }

    public static function validMetrics(array $state): bool
    {
        if (isset($state['storage']) && ! self::validStorage($state['storage'])) {
            return false;
        }
        if (isset($state['repository'])) {
            $s = $state['repository'];
            if (! is_array($s)) {
                return false;
            }
            foreach (['total_size', 'total_uncompressed_size', 'logical_bytes', 'snapshots_count'] as $key) {
                if (! is_int($s[$key] ?? null) || $s[$key] < 0) {
                    return false;
                }
            }
            foreach (['compression_ratio', 'compression_space_saving'] as $key) {
                if (! is_numeric($s[$key] ?? null) || ! is_finite((float) $s[$key])) {
                    return false;
                }
            }
            if (isset($s['repository_file_bytes']) && (! is_int($s['repository_file_bytes']) || $s['repository_file_bytes'] < 0)) {
                return false;
            }
            if (! is_array($s['snapshots'] ?? null)) {
                return false;
            }
            foreach ($s['snapshots'] as $uuid => $storage) {
                if (! is_string($uuid) || ! preg_match('/^[a-f0-9-]{36}$/D', $uuid) || ! self::validStorage($storage)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function register(): void
    {
        // The same native BytesColumn is used in the customer table and admin
        // host relation. Non-backup records/other schemas keep its exact format.
        BytesColumn::configureUsing(function (BytesColumn $column): void {
            if ($column->getName() !== 'bytes') {
                return;
            }
            $column->formatStateUsing(fn ($state, ?Model $record) => $record instanceof Backup && $record->backupHost->schema === 'restic'
                ? self::size($record) : ($state ? convert_bytes_to_readable($state) : ''))
                ->description(fn (?Model $record) => $record instanceof Backup && $record->backupHost->schema === 'restic' ? 'Added compressed data' : null)
                ->tooltip(fn (?Model $record) => $record instanceof Backup ? self::tooltip($record) : null);
        });
    }

    public static function snapshot(Backup $backup): ?array
    {
        if (Installation::missing() !== []) {
            return null;
        }
        $query = DB::table('ibyte_restic_operations')->where('host_id', $backup->backup_host_id)
            ->where('server_uuid', $backup->server->uuid)->where('node_id', $backup->server->node_id)
            ->where('status', 'succeeded')->whereNotNull('metrics');
        $create = (clone $query)->where('operation', 'create')->where('backup_uuid', $backup->uuid)->first();
        $stats = (clone $query)->whereIn('operation', ['create', 'stats', 'prune'])->latest('updated_at')->first();
        foreach (array_filter([$create, $stats]) as $row) {
            $metrics = json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR);
            $storage = $row->operation === 'create' && $row->backup_uuid === $backup->uuid
                ? ($metrics['storage'] ?? null) : ($metrics['repository']['snapshots'][$backup->uuid] ?? null);
            if ($storage !== null) {
                return $storage;
            }
        }

        return null;
    }

    public static function size(Backup $backup): string
    {
        if ($backup->backupHost->schema !== 'restic') {
            return $backup->bytes ? convert_bytes_to_readable($backup->bytes) : '';
        }
        if (! $backup->completed_at || ! $backup->is_successful) {
            return '';
        }
        $storage = self::snapshot($backup);

        return $storage === null ? 'Unknown' : convert_bytes_to_readable($storage['data_added_packed']);
    }

    public static function tooltip(Backup $backup): ?string
    {
        if ($backup->backupHost->schema !== 'restic') {
            return null;
        }
        $s = self::snapshot($backup);
        if ($s === null) {
            return 'Storage measurement pending automatic synchronization. Logical restore bytes are not added storage.';
        }

        return 'New compressed data added at creation: '.convert_bytes_to_readable($s['data_added_packed']).
            '; logical restore size: '.convert_bytes_to_readable($s['total_bytes_processed']).
            '. Shared chunks remain usable after older snapshots are deleted. This is not exclusive disk usage or reclaimable space; repository metadata is additional.';
    }

    public static function summary(BackupHost $host): string
    {
        if (Installation::missing() !== []) {
            return 'Restic installation is incomplete. Use Finish installation / Repair tables before refreshing statistics.';
        }
        $rows = DB::table('ibyte_restic_operations')->where('host_id', $host->id)
            ->whereIn('operation', ['create', 'stats', 'prune'])->where('status', 'succeeded')->whereNotNull('metrics')
            ->where('metrics', 'like', '%"repository"%')
            ->latest('updated_at')->get()->unique(fn ($r) => $r->node_id.':'.$r->server_uuid);
        $lines = ['Measurements are captured automatically after backups and pruning. Added bytes per backup are historical, not exclusive/reclaimable space. Dedup estimate = total logical restore bytes / unique uncompressed referenced blob bytes. Compression = uncompressed/compressed blob sizes.'];
        foreach ($rows as $row) {
            $s = json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR)['repository'] ?? null;
            if ($s === null) {
                continue;
            }
            $dedup = $s['total_uncompressed_size'] > 0 ? number_format($s['logical_bytes'] / $s['total_uncompressed_size'], 2).'x' : 'N/A';
            $compression = $s['compression_ratio'] > 0 ? number_format($s['compression_ratio'], 2).'x' : 'N/A';
            $actual = isset($s['repository_file_bytes']) ? convert_bytes_to_readable($s['repository_file_bytes']) : 'Unavailable for remote backend';
            $lines[] = "Node {$row->node_id}, server {$row->server_uuid}, measured {$row->updated_at}: ".
                'repository files '.$actual.'; referenced compressed blobs '.convert_bytes_to_readable($s['total_size']).
                '; total logical restores '.convert_bytes_to_readable($s['logical_bytes']).
                "; snapshots {$s['snapshots_count']}; dedup estimate {$dedup}; compression {$compression}; compression saving ".number_format($s['compression_space_saving'], 2).'%. Repository files include metadata and unpruned packs; file lengths are not allocated filesystem blocks or cloud billing.';
        }
        if ($rows->isEmpty()) {
            $lines[] = 'Pending automatic storage measurement. Existing cached measurements remain available when a node is offline.';
        }

        return implode("\n\n", $lines);
    }

    public static function repository(RecoveryRepository $repository): ?object
    {
        return DB::table('ibyte_restic_operations')->where('host_id', $repository->host_id)
            ->where('node_id', $repository->node_id)->where('server_uuid', $repository->server_uuid)
            ->where('status', 'succeeded')->whereNotNull('metrics')->where('metrics', 'like', '%"repository"%')
            ->when(($repository->metadata['generation'] ?? 0) > 0, fn ($query) => $query->where('payload->generation', $repository->metadata['generation']))
            ->latest('updated_at')->first();
    }

    public static function serverSummary(Server $server): string
    {
        if (Installation::missing() !== []) {
            return 'Storage setup is incomplete. Ask an administrator to finish Restic installation.';
        }
        $ids = $server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->distinct()->pluck('backup_host_id');
        $lines = [];
        foreach ($ids as $id) {
            $repository = RecoveryRepository::where('host_id', $id)->where('node_id', $server->node_id)->where('server_uuid', $server->uuid)->first();
            $row = DB::table('ibyte_restic_operations')->where('host_id', $id)->where('node_id', $server->node_id)
                ->where('server_uuid', $server->uuid)->where('status', 'succeeded')->where('metrics', 'like', '%"repository"%')
                ->when(($repository?->metadata['generation'] ?? 0) > 0, fn ($query) => $query->where('payload->generation', $repository->metadata['generation']))
                ->latest('updated_at')->first();
            if ($row && ($stats = json_decode($row->metrics, true)['repository'] ?? null)) {
                $dedup = $stats['total_uncompressed_size'] > 0 ? round($stats['logical_bytes'] / $stats['total_uncompressed_size'], 2).'x' : 'N/A';
                $lines[] = 'Measured '.$row->updated_at.': repository '.(isset($stats['repository_file_bytes']) ? convert_bytes_to_readable($stats['repository_file_bytes']) : 'remote physical usage unavailable')
                    .'; snapshots '.$stats['snapshots_count'].'; dedup estimate '.$dedup.'; compression '.round($stats['compression_ratio'], 2).'x.';
            }
        }

        return $lines ? implode("\n", $lines) : 'Storage measurement pending automatic synchronization.';
    }
}
