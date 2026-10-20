<?php

namespace IByte\Restic\Console;

use IByte\Restic\Support\NodeDiagnostics;
use Illuminate\Console\Command;

final class DiagnoseNode extends Command
{
    protected $signature = 'restic:diagnose-node {host : Backup host ID} {node : Node ID} {--operation= : Optional saved operation UUID}';

    protected $description = 'Read node readiness and optional saved job status without fetching keys or submitting work';

    public function handle(): int
    {
        $host = filter_var($this->argument('host'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $node = filter_var($this->argument('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($host === false || $node === false) {
            $this->error('Host and node must be positive integer IDs.');

            return self::FAILURE;
        }
        $result = NodeDiagnostics::inspect($host, $node, $this->option('operation'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['ready'] && ! isset($result['operation']['problem']) ? self::SUCCESS : self::FAILURE;
    }
}
