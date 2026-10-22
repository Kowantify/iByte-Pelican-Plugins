$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$source = Join-Path $project 'panel-plugin'
$manifest = Get-Content (Join-Path $source 'plugin.json') -Raw | ConvertFrom-Json
if ($manifest.id -ne 'ibyte-prometheus-exporter-addon-restic' -or $manifest.version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid plugin manifest' }
$release = Join-Path $project 'release/Panel Plugin'
if (-not (Test-Path -LiteralPath $release)) { New-Item -ItemType Directory -Path $release | Out-Null }
$output = Join-Path $release ('ibyte-prometheus-exporter-addon-restic-v' + $manifest.version + '.zip')
if (Test-Path -LiteralPath $output) { Remove-Item -LiteralPath $output -Force }
$archive = [System.IO.Compression.ZipFile]::Open($output, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, ('ibyte-prometheus-exporter-addon-restic/' + $relative)) | Out-Null
    }
} finally { $archive.Dispose() }
Get-FileHash -Algorithm SHA256 -LiteralPath $output | Select-Object Path,Hash
