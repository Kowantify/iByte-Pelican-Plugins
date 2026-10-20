// Generate a review-only upstream proposal. Never writes into the Panel checkout.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const base=path.join(root,'.build/restore-proposal-base');
const next=path.join(root,'.build/restore-proposal-next');
const write=(dir,p,text)=>{fs.mkdirSync(path.dirname(path.join(dir,p)),{recursive:true});fs.writeFileSync(path.join(dir,p),text.replaceAll('\r\n','\n'));};
const api='app/Http/Controllers/Api/Client/Servers/BackupController.php';
const ui='app/Filament/Server/Resources/Backups/BackupResource.php';
let source=fs.readFileSync(path.join(root,'.upstream/panel',api),'utf8');write(base,api,source);
source=source.replace('use App\\Repositories\\Daemon\\DaemonBackupRepository;','use App\\Services\\Backups\\RestoreBackupService;').replace('private readonly DaemonBackupRepository $daemonRepository','private readonly RestoreBackupService $restoreBackupService').replace('$this->daemonRepository->setServer($server)->restore($backup, $url, $request->input(\'truncate\'));','$this->restoreBackupService->restore($backup, $url, $request->input(\'truncate\'));');write(next,api,source);
source=fs.readFileSync(path.join(root,'.upstream/panel',ui),'utf8');write(base,ui,source);
source=source.replace('use App\\Repositories\\Daemon\\DaemonBackupRepository;','use App\\Services\\Backups\\RestoreBackupService;').replaceAll('DaemonBackupRepository $daemonRepository','RestoreBackupService $restoreBackupService').replaceAll('$downloadLinkService, $daemonRepository, $backup','$downloadLinkService, $restoreBackupService, $backup').replace('$daemonRepository->setServer($server)->restore($backup, $url, $data[\'truncate\']);','$restoreBackupService->restore($backup, $url, $data[\'truncate\']);');write(next,ui,source);
write(next,'app/Extensions/BackupAdapter/RestoresBackupsInterface.php',`<?php

namespace App\\Extensions\\BackupAdapter;

use App\\Models\\Backup;

/** Optional backup-provider restore dispatch; callers retain native RBAC and status protection. */
interface RestoresBackupsInterface
{
    public function restoreBackup(Backup $backup, bool $truncate): void;
}
`);
write(next,'app/Services/Backups/RestoreBackupService.php',`<?php

namespace App\\Services\\Backups;

use App\\Extensions\\BackupAdapter\\BackupAdapterService;
use App\\Extensions\\BackupAdapter\\RestoresBackupsInterface;
use App\\Models\\Backup;
use App\\Repositories\\Daemon\\DaemonBackupRepository;
use RuntimeException;

final class RestoreBackupService
{
    public function __construct(private BackupAdapterService $schemas, private DaemonBackupRepository $daemon) {}

    public function restore(Backup $backup, ?string $url = null, bool $truncate = false): void
    {
        $schema = $this->schemas->get($backup->backupHost->schema);
        if (!$schema) { throw new RuntimeException('Unknown backup adapter.'); }
        if ($schema instanceof RestoresBackupsInterface) {
            $schema->restoreBackup($backup, $truncate);
            return;
        }
        $this->daemon->setServer($backup->server)->restore($backup, $url, $truncate);
    }
}
`);
console.log('Prepared review-only proposal trees; pinned Panel source unchanged.');
