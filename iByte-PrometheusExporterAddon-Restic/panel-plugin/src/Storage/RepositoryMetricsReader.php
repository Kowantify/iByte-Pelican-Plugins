<?php

namespace IByte\PrometheusExporterAddonRestic\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the exact rows the iByte-Restic admin Statistics page renders:
 * per current repository, the newest succeeded operation row carrying a
 * "repository" metrics blob, generation-gated the same way
 * StorageReport::repository() gates it. Removed repositories are excluded.
 *
 * Read-only. The add-on never writes to iByte-Restic tables. Missing or
 * invalid measurements are omitted from the result (pending), never zero.
 */
final class RepositoryMetricsReader
{
    /** @var bool|null memoized schema gate result */
    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= self::tablesPresent();
    }

    private static function tablesPresent(): bool
    {
        if (! Schema::hasTable('ibyte_restic_repositories') || ! Schema::hasTable('ibyte_restic_operations')) {
            return false;
        }
        if (! Schema::hasColumn('ibyte_restic_operations', 'metrics')) {
            return false;
        }
        foreach (['host_id', 'node_id', 'server_uuid', 'owner_id', 'owner_username',
            'server_name', 'metadata', 'backend', 'profile'] as $column) {
            if (! Schema::hasColumn('ibyte_restic_repositories', $column)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> one latest measurement per current repository identity */
    public static function latest(): array
    {
        if (! self::available()) {
            return [];
        }

        $repositories = DB::table('ibyte_restic_repositories')->get([
            'host_id', 'node_id', 'server_uuid', 'backend', 'profile',
            'owner_id', 'owner_username', 'server_name', 'metadata',
        ]);

        $rows = [];
        foreach ($repositories as $repo) {
            $metadata = json_decode((string) $repo->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            // Mirror the admin Statistics page: removed repositories are excluded.
            if (isset($metadata['repository_deleted_at'])) {
                continue;
            }
            $generation = is_int($metadata['generation'] ?? null) ? $metadata['generation'] : 0;

            // Identical row selection to StorageReport::repository() minus the
            // eloquent model: newest succeeded repo-metrics row for the identity,
            // pinned to the repository's current generation when one is declared.
            $op = DB::table('ibyte_restic_operations')
                ->where('host_id', $repo->host_id)
                ->where('node_id', $repo->node_id)
                ->where('server_uuid', $repo->server_uuid)
                ->where('status', 'succeeded')
                ->whereNotNull('metrics')
                ->where('metrics', 'like', '%"repository"%')
                ->when($generation > 0, fn ($query) => $query->where('payload->generation', $generation))
                ->latest('updated_at')
                ->first();
            if ($op === null) {
                continue; // Measurement pending — the admin page shows nothing, we emit nothing.
            }

            $parsed = self::repository((string) $op->metrics);
            if ($parsed === null) {
                continue; // Invalid upstream blob: omitted, never rendered as zero.
            }

            $rows[] = [
                'server_uuid' => (string) $repo->server_uuid,
                'node_id' => (int) $repo->node_id,
                'host_id' => (int) $repo->host_id,
                'owner_id' => (int) $repo->owner_id,
                'owner' => (string) $repo->owner_username,
                'server_name' => (string) $repo->server_name,
                'backend' => (string) $repo->backend,
                'profile' => (string) $repo->profile,
                'generation' => $generation > 0 ? (string) $generation : '',
                'measured_at' => (int) strtotime((string) $op->updated_at),
            ] + $parsed;
        }

        return $rows;
    }

    /**
     * Validates and whitelists the repository blob, mirroring the repository
     * branch of StorageReport::validMetrics(). Returns null on anything invalid.
     *
     * @return array<string, mixed>|null
     */
    public static function repository(string $metricsJson): ?array
    {
        $metrics = json_decode($metricsJson, true);
        if (! is_array($metrics)) {
            return null;
        }
        $state = $metrics['repository'] ?? null;
        if (! is_array($state)) {
            return null;
        }
        foreach (['total_size', 'total_uncompressed_size', 'logical_bytes', 'snapshots_count'] as $key) {
            if (! is_int($state[$key] ?? null) || $state[$key] < 0) {
                return null;
            }
        }
        foreach (['compression_ratio', 'compression_space_saving'] as $key) {
            if (! is_numeric($state[$key] ?? null) || ! is_finite((float) $state[$key])) {
                return null;
            }
        }
        // Optional upstream: repository_file_bytes is unavailable for remote backends.
        if (isset($state['repository_file_bytes'])
            && (! is_int($state['repository_file_bytes']) || $state['repository_file_bytes'] < 0)) {
            return null;
        }

        return [
            'file_bytes' => is_int($state['repository_file_bytes'] ?? null) ? $state['repository_file_bytes'] : null,
            'file_measured' => isset($state['repository_file_bytes']) && is_int($state['repository_file_bytes']) ? 1 : 0,
            'blobs_compressed_bytes' => (int) $state['total_size'],
            'blobs_uncompressed_bytes' => (int) $state['total_uncompressed_size'],
            'logical_bytes' => (int) $state['logical_bytes'],
            'snapshots_count' => (int) $state['snapshots_count'],
            'compression_ratio' => (float) $state['compression_ratio'],
            'compression_space_saving' => (float) $state['compression_space_saving'],
        ];
    }
}