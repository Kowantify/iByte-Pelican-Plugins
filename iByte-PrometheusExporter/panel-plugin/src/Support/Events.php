<?php

namespace IByte\PrometheusExporter\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;

final class Events
{
    public static function write(string $event, array $context = [], string $level = 'info'): void
    {
        // Never accept raw responses, exception messages, URLs, or credentials.
        $safe = array_intersect_key($context, array_flip(['admin_id', 'node_id', 'reason']));
        Log::build(['driver' => 'daily', 'path' => storage_path('logs/ibyte-prometheus.jsonl'),
            'days' => 7, 'permission' => 0600, 'formatter' => JsonFormatter::class])->log($level, $event, $safe);
    }
}
