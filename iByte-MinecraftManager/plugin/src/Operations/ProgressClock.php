<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

/** Monotonic processing durations; recurring phases accumulate rather than reset. */
final class ProgressClock
{
    private float $started;
    private float $changed;
    private string $startedAt;
    private string $phaseStartedAt;
    private ?string $phase = null;
    private array $durations = [];

    public function __construct()
    {
        $this->started = $this->changed = hrtime(true) / 1e9;
        $this->startedAt = $this->phaseStartedAt = gmdate('c');
    }

    public function snapshot(array $state): array
    {
        $now = hrtime(true) / 1e9;
        if ($this->phase !== ($state['phase'] ?? 'prepare')) {
            if ($this->phase !== null) { $this->durations[$this->phase] = ($this->durations[$this->phase] ?? 0) + $now - $this->changed; }
            $this->phase = $state['phase'] ?? 'prepare'; $this->changed = $now; $this->phaseStartedAt = gmdate('c');
        }
        $durations = $this->durations;
        $durations[$this->phase] = ($durations[$this->phase] ?? 0) + $now - $this->changed;
        return [...$state, 'started_at'=>$this->startedAt,'phase_started_at'=>$this->phaseStartedAt,
            'elapsed_seconds'=>round($now-$this->started, 3),'phase_elapsed_seconds'=>round($now-$this->changed, 3),
            'phase_seconds'=>array_map(static fn($seconds)=>round($seconds, 3),$durations)];
    }
}
