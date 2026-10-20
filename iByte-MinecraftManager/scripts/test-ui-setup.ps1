$ErrorActionPreference='Stop'
$taskRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location -LiteralPath $taskRoot
$phpPath=Join-Path $taskRoot '.tools/php/php.exe'
$env:DB_DATABASE='imm-ui-install.sqlite'
$env:PATH=(Join-Path $taskRoot '.tools/php')+';'+(Join-Path $taskRoot '.tools/node/node-v22.23.3-win-x64')+';'+(Join-Path $taskRoot '.tools/corepack/v1/yarn/1.22.22/bin')+';'+$env:PATH
Copy-Item -LiteralPath '.research/panel/database/imm-preview.sqlite' -Destination '.research/panel/database/imm-ui-install.sqlite'
& $phpPath scripts/prepare-ui-install.php
if($LASTEXITCODE -ne 0){throw 'UI database preparation failed'}
& $phpPath .build/prime-ui.php
if($LASTEXITCODE -ne 0){throw 'Old import fixture failed'}
& $phpPath .build/ui-fixture-admin.php
if($LASTEXITCODE -ne 0){throw 'UI administrator preparation failed'}
$worker=Start-Process -WindowStyle Hidden -FilePath $phpPath -WorkingDirectory $taskRoot -ArgumentList @('scripts/ui-install-worker.php','--prime-old-migration') -RedirectStandardOutput '.build/ui-install-worker-22.log' -RedirectStandardError '.build/ui-install-worker-22-error.log' -PassThru
$web=Start-Process -WindowStyle Hidden -FilePath $phpPath -WorkingDirectory $taskRoot -ArgumentList @('-d','opcache.enable_cli=1','-S','127.0.0.1:8096','-t','.research/panel/public','scripts/browser-router.php') -RedirectStandardOutput '.build/ui-install-web-22.log' -RedirectStandardError '.build/ui-install-web-22-error.log' -PassThru
$edgePath=Join-Path ${env:ProgramFiles(x86)} 'Microsoft/Edge/Application/msedge.exe'
$profile=Join-Path $taskRoot '.build/edge-profile-22'
$edge=Start-Process -WindowStyle Hidden -FilePath $edgePath -ArgumentList @('--headless=new','--no-sandbox','--disable-gpu','--no-first-run','--remote-debugging-port=9226',('--user-data-dir="'+$profile+'"'),'about:blank') -RedirectStandardOutput '.build/edge-22.log' -RedirectStandardError '.build/edge-22-error.log' -PassThru
@{worker=$worker.Id;web=$web.Id;edge=$edge.Id;profile=$profile}|ConvertTo-Json|Set-Content -LiteralPath '.build/ui-install-22-processes.json' -Encoding UTF8
for($attempt=0;$attempt -lt 20;$attempt++){
    Start-Sleep -Milliseconds 500
    if(Test-Path -LiteralPath '.build/ui-install-worker-ready.json'){
        $ready=Get-Content -Raw -LiteralPath '.build/ui-install-worker-ready.json'|ConvertFrom-Json
        if($ready.pid -eq $worker.Id){Write-Output 'Fresh native UI worker ready; cached old failure primed.';exit 0}
    }
}
throw 'Fresh UI worker did not become ready'
