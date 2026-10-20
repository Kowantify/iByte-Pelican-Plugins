<?php

namespace IByte\Restic\Console;

use IByte\Restic\Support\RuntimeDiagnostics;
use Illuminate\Console\Command;

final class DiagnoseRuntime extends Command
{
    protected $signature = 'restic:diagnose';

    protected $description = 'Check Restic source, runtime dependencies and safety bindings without contacting nodes or changing backups';

    public function handle(): int
    {
        $result = RuntimeDiagnostics::snapshot();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
