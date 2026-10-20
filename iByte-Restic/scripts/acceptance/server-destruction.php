<?php

use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use IByte\Restic\Recovery\RecoveryRepository;
use IByte\Restic\Recovery\ServerDestruction;
use Illuminate\Support\Facades\DB;

// Included only after the dedicated disposable Linux live-node bootstrap.
if (getenv('IBYTE_DISPOSABLE_LINUX') !== '1' || getenv('IBYTE_TEST_SERVER_DESTRUCTION_ONLY') !== '1' || ! is_file('/opt/ibyte-acceptance/fixture') || PHP_OS_FAMILY !== 'Linux') {
    exit(1);
}
$world = '/var/lib/ibyte-acceptance/volumes/'.$server->uuid.'/world.dat';
liveCheck(is_dir(dirname($world)) && ! is_link(dirname($world)), 'disposable actual Wings server root is present');
file_put_contents($world, str_repeat('destructive-mode-disposable-world', 100));
$backup = app(InitiateBackupService::class)->setIsLocked(true)->setIgnoredFiles([])->handle($server, 'Disposable locked snapshot before destruction');
liveWait(fn () => $backup->fresh()->completed_at !== null);
liveCheck($backup->fresh()->is_successful && $backup->fresh()->is_locked, 'real native database queue creates a locked Restic snapshot');
$repo = RecoveryRepository::where('server_uuid', $server->uuid)->firstOrFail();
$repoPath = $repo->metadata['repository'];
liveCheck(str_starts_with($repoPath, '/var/lib/ibyte-restic/repositories/') && is_dir($repoPath), 'captured repository is real and outside game data');
$secretBefore = $repo->getRawOriginal('secret');
$sentinel = '/var/lib/ibyte-acceptance/backups/destruction-neighbor-sentinel';
file_put_contents($sentinel, 'disposable-neighbor-preserved');
$request = app(ServerDestruction::class)->run($admin, $server->fresh(), 'disposable-live-fixture');
liveCheck(! Server::whereKey($server->id)->exists() && ! file_exists(dirname($world)), 'native force deletion removes the Panel server and actual Wings game root');
liveCheck(is_dir($repoPath) && DB::table('ibyte_restic_operations')->where('request_uuid', $request)->value('status') === 'queued', 'repository destruction remains a committed queued action after native deletion');
liveCheck(DB::table('ibyte_restic_operations')->where('server_uuid', $server->uuid)->where('operation', 'final-backup')->doesntExist(), 'destructive mode never prepares a retained final backup');
liveWait(fn () => DB::table('ibyte_restic_operations')->where('request_uuid', $request)->value('status') === 'succeeded');
liveCheck(! file_exists($repoPath) && isset($repo->fresh()->metadata['repository_deleted_at']), 'actual authenticated Wings force deletion removes the complete real Restic repository and confirms it');
liveCheck($repo->fresh()->getRawOriginal('secret') === $secretBefore && file_get_contents($sentinel) === 'disposable-neighbor-preserved', 'encrypted key history and neighboring backup data remain intact');
echo "Real local native server/repository destruction passed; only Panel bootstrap inventory and backup broadcasting were mocked.\n";
