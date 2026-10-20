<?php

namespace IByte\Restic\Integration;

use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use IByte\Restic\Recovery\FinalBackupPreparation;
use IByte\Restic\Recovery\RetainedDeletion;
use IByte\Restic\Recovery\ServerDestruction;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Pinned internal service binding: the model deleting event would run too late,
// after upstream already asked Wings to destroy the server's data.
final class GuardedServerDeletion extends ServerDeletionService
{
    public function handle(Server $server): void
    {
        if (FinalBackupPreparation::applies($server) && ! app(RetainedDeletion::class)->active($server) && ! app(ServerDestruction::class)->active($server)) {
            throw new ConflictHttpException('Restic blocks ordinary server deletion. Use the native confirmed Delete action to prepare and verify a final backup before deleting this server.');
        }
        LifecycleGuard::assertClear($server, app(RetainedDeletion::class)->active($server));
        parent::handle($server);
    }
}
