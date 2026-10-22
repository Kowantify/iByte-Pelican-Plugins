$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifest = Get-Content (Join-Path $project 'plugin.json') -Raw | ConvertFrom-Json
if ($manifest.id -ne 'ibyte-egg-changer' -or $manifest.version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid plugin manifest' }
$output = Join-Path $project ('release/Panel Plugin/ibyte-egg-changer-v' + $manifest.version + '.zip')
if (Test-Path -LiteralPath $output) { Remove-Item -LiteralPath $output -Force }
$archive = [System.IO.Compression.ZipFile]::Open($output, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in 'LICENSE', 'plugin.json', 'README.md') {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $project $file), ('ibyte-egg-changer/' + $file)) | Out-Null
    }
    Get-ChildItem -LiteralPath (Join-Path $project 'src'), (Join-Path $project 'database'), (Join-Path $project 'resources') -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($project.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, ('ibyte-egg-changer/' + $relative)) | Out-Null
    }
} finally { $archive.Dispose() }
Get-FileHash -Algorithm SHA256 -LiteralPath $output | Select-Object Path,Hash
