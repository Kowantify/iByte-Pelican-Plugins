<?php

namespace IByte\Restic\Console;

use IByte\Restic\Support\Installation;
use Illuminate\Console\Command;
use Throwable;

final class RepairInstallation extends Command
{
    protected $signature = 'restic:repair-installation';

    protected $description = 'Finish additive Restic plugin migrations without deleting backups or contacting Wings';

    public function handle(): int
    {
        try {
            Installation::repair();
            $this->info('Restic plugin tables are ready. Backups and repositories were not deleted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Restic repair failed. Inspect the protected Panel log; do not uninstall or wipe the database.');

            return self::FAILURE;
        }
    }
}
