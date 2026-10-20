<?php

namespace IByte\PrometheusExporter\Events;

/**
 * Add-on extension point for GET /ibyte-prometheus-exporter/metrics.
 * Listeners may append pre-rendered exposition lines for their additional
 * metric families; every line is validated here so the base endpoint keeps
 * its guarantees (namespace, numeric values, text-format shape) no matter
 * what a listener tries to add. Invalid lines are dropped silently.
 */
final class MetricsRendered
{
    /** @var list<string> */
    private array $extra = [];

    public function __construct(public readonly string $panelId) {}

    /**
     * One pre-rendered exposition line. Valid shapes:
     *   # HELP ibyte_pelican_x ...   /   # TYPE ibyte_pelican_x gauge
     *   ibyte_pelican_x{labels} value   |   ibyte_pelican_x value
     */
    public function append(string $line): void
    {
        $line = rtrim($line, "\r\n");
        $metadata = str_starts_with($line, '# HELP ibyte_pelican_') || str_starts_with($line, '# TYPE ibyte_pelican_');
        $metric = (bool) preg_match('/^ibyte_pelican_[a-z][a-z0-9_]*(\{[^}]*\})? (-?\d+(?:\.\d+)?)$/', $line);
        if ($metadata || $metric) {
            $this->extra[] = $line;
        }
    }

    /** @return list<string> */
    public function extraLines(): array
    {
        return $this->extra;
    }
}