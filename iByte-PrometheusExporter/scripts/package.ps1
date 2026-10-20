$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$source = Join-Path $project 'panel-plugin'
$manifest = Get-Content (Join-Path $source 'plugin.json') -Raw | ConvertFrom-Json
if ($manifest.version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.]+)?$') { throw 'Invalid package version' }
$output = Join-Path $project ('release/Panel Plugin/ibyte-prometheus-exporter-' + $manifest.version + '.zip')
if (Test-Path -LiteralPath $output) { Remove-Item -LiteralPath $output -Force }
$archive = [System.IO.Compression.ZipFile]::Open($output, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
        $entry = 'ibyte-prometheus-exporter/' + $relative
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $entry) | Out-Null
    }
} finally { $archive.Dispose() }
Get-FileHash -Algorithm SHA256 -LiteralPath $output | Select-Object Path,Hash
