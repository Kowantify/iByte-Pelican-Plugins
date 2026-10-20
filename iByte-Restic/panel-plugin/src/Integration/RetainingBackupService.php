<?php

namespace IByte\Restic\Integration;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use IByte\Restic\Backups\Retention;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Recovery\RecoveryVault;
use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Source-pinned container bridge; the parent still creates and schedules backups. */
final class RetainingBackupService extends InitiateBackupService
{
    private array $resticIgnored = [];

    public function setIgnoredFiles(?array $ignored): self
    {
        parent::setIgnoredFiles($ignored);
        $this->resticIgnored = array_values(array_filter($ignored ?? [], fn ($value) => strlen($value) > 0));

        return $this;
    }

    public function handle(Server $server, ?string $name = null, bool $override = false): Backup
    {
        if (! Retention::applies($server)) {
            return parent::handle($server, $name, $override);
        }
        Compatibility::assertSupported();
        Installation::assertReady();
        if (mb_strlen($name ?? '') > 255 || count($this->resticIgnored) > 128
            || collect($this->resticIgnored)->contains(fn ($pattern) => strlen($pattern) > 256 || preg_match('/[\x00\r\n]/', $pattern) || str_starts_with($pattern, '-'))) {
            throw new HttpException(409, 'Backup name or ignored-file patterns exceed the supported limits. No snapshot was removed.', headers: ['Retry-After' => 0]);
        }

        return DB::transaction(function () use ($server, $name, $override) {
            $server = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            LifecycleGuard::assertNotReserved($server);
            if ($server->transfer) {
                throw new HttpException(409, 'Wait for the native server transfer to finish before creating backups.', headers: ['Retry-After' => 0]);
            }
            $reject = fn ($message) => throw new HttpException(409, $message, headers: ['Retry-After' => 0]);
            if ($server->backup_limit <= 0) {
                $reject('This server has no backup slots.');
            }
            if (in_array(config('queue.default'), ['sync', 'null'], true)) {
                throw new \RuntimeException('Restic requires a durable Panel queue worker.');
            }
            // Check native throttle before any irreversible snapshot removal.
            $period = (int) config('backups.throttles.period');
            if ($period > 0 && $server->backups()->nonFailed()->where('created_at', '>=', now()->subSeconds($period))->count() >= config('backups.throttles.limit')) {
                $reject('The native backup creation throttle has been reached. Try again later.');
            }
            if ($server->backups()->nonFailed()->count() >= $server->backup_limit) {
                if (! $override && ! user()?->can(SubuserPermission::BackupDelete, $server)) {
                    $reject('Replacing an old backup requires backup delete permission.');
                }
                $oldest = Retention::candidate($server, reserve: true);
                if (! $oldest) {
                    $reject('No completed unlocked Restic backup can be replaced. Unlock a backup or wait for pending work.');
                }
                // Validate opt-in/readiness before removing a previously usable backup.
                $probe = new Backup(['server_id' => $server->id, 'backup_host_id' => Retention::host($server)->id,
                    'uuid' => (string) Str::uuid()]);
                $probe->setRelation('server', $server);
                app(Transport::class)->context($probe);
                RecoveryVault::capture($probe);
                // One eviction per request; an operator lowering a quota below usage
                // must explicitly resolve the excess rather than mass-deleting here.
                if ($server->backups()->nonFailed()->count() > $server->backup_limit) {
                    $reject('Backup usage exceeds the new limit. Remove excess unlocked backups before creating another.');
                }
                app(DeleteBackupService::class)->handle($oldest);
                Activity::event('server:backup.retention-delete')->subject($server)->property(['backup_uuid' => $oldest->uuid, 'reason' => 'quota replacement'])->log();
            }

            return parent::handle($server, $name, false);
        });
    }
}
