<?php

namespace IByte\PrometheusExporter\Support;

final class Normalizer
{
    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) return null;
        $number = (float) $value;
        return is_finite($number) && $number >= 0 ? $number : null;
    }

    /** Only whitelisted fields survive the raw Wings response. */
    public static function server(array $raw, object $inventory, bool $binaryPrefix = true): ?array
    {
        if (!is_array($raw['configuration'] ?? null)) return null;
        if (($raw['configuration']['uuid'] ?? null) !== $inventory->uuid) return null;
        $usage = $raw['utilization'] ?? null;
        if (!is_array($usage)) return null;
        $state = ($raw['is_suspended'] ?? false) === true ? 'suspended' : ($raw['state'] ?? 'unknown');
        if (!in_array($state, ['running', 'offline', 'starting', 'stopping', 'suspended', 'unknown'], true)) {
            $state = 'unknown';
        }
        $cpu = self::number($usage['cpu_absolute'] ?? null);
        $memory = self::number($usage['memory_bytes'] ?? null);
        $disk = self::number($usage['disk_bytes'] ?? null);
        $cpuLimit = self::number($inventory->cpu);
        $memoryLimit = self::number($inventory->memory);
        $diskLimit = self::number($inventory->disk);
        // Network/disk-io/uptime/limits come from the utilization object only; the
        // sensitive configuration blob is never exported.
        $network = is_array($usage['network'] ?? null) ? $usage['network'] : [];
        $diskIo = is_array($usage['disk_io'] ?? null) ? $usage['disk_io'] : [];
        $wingsMemoryLimit = self::number($usage['memory_limit_bytes'] ?? null);

        // Limits come from Panel inventory, not the sensitive Wings configuration.
        return [
            'uuid' => $inventory->uuid,
            'name' => (string) ($inventory->name ?? ''),
            'owner' => (string) ($inventory->user->username ?? ''),
            'state' => $state,
            'cpu' => $cpu === null ? null : $cpu / 100,
            'memory' => $memory,
            'disk' => $disk,
            'network_rx' => self::number($network['rx_bytes'] ?? null),
            'network_tx' => self::number($network['tx_bytes'] ?? null),
            'disk_read' => self::number($diskIo['read_bytes'] ?? null),
            'disk_write' => self::number($diskIo['write_bytes'] ?? null),
            'uptime' => self::number($usage['uptime'] ?? null),
            'wings_memory_limit' => $wingsMemoryLimit === null || $wingsMemoryLimit === 0.0 ? null : $wingsMemoryLimit,
            'cpu_limit' => $cpuLimit === null || $cpuLimit === 0.0 ? null : $cpuLimit / 100,
            'memory_limit' => $memoryLimit === null || $memoryLimit === 0.0 ? null : round($binaryPrefix ? $memoryLimit : $memoryLimit / 1.048576) * 1048576,
            'disk_limit' => $diskLimit === null || $diskLimit === 0.0 ? null : round($binaryPrefix ? $diskLimit : $diskLimit / 1.048576) * 1048576,
            'unlimited_cpu' => $cpuLimit === null ? null : $cpuLimit === 0.0,
            'unlimited_memory' => $memoryLimit === null ? null : $memoryLimit === 0.0,
            'unlimited_disk' => $diskLimit === null ? null : $diskLimit === 0.0,
        ];
    }
}