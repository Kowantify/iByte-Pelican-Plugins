$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifestPath = Join-Path $root 'plugin/plugin.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$version = [string]$manifest.version
if ($manifest.id -ne 'ibyte-minecraft-manager' -or $version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid plugin manifest.' }

$dates = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'release-dates.json') -Raw | ConvertFrom-Json
if (-not $dates.PSObject.Properties[$version]) { throw 'Record a fixed release timestamp before packaging.' }
$epoch = [DateTimeOffset]::Parse([string]$dates.$version).ToUnixTimeSeconds()
if (($epoch % 2) -ne 0) { throw 'ZIP timestamps require an even second.' }

$outputDirectory = Join-Path $root 'release/Panel Plugin'
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
$target = Join-Path $outputDirectory ($manifest.id + '-v' + $version + '.zip')
if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target -Force }

$pluginRoot = (Resolve-Path (Join-Path $root 'plugin')).Path
$entries = [System.Collections.Generic.SortedDictionary[string,string]]::new([StringComparer]::Ordinal)
$entries['LICENSE'] = Join-Path $root 'LICENSE'
Get-ChildItem -LiteralPath $pluginRoot -Recurse -File | ForEach-Object {
    $relative = $_.FullName.Substring($pluginRoot.Length + 1).Replace('\', '/')
    if ($relative.StartsWith('tests/')) { return }
    if (($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or $_.Extension -notin '.php','.json','.md') {
        throw "Unexpected package input: $relative"
    }
    $entries[$relative] = $_.FullName
}

$archive = [IO.Compression.ZipFile]::Open($target, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in $entries.Keys) {
        $entry = $archive.CreateEntry($manifest.id + '/' + $relative, [IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::FromUnixTimeSeconds($epoch)
        $entry.ExternalAttributes = ([int]0x81A4 -shl 16)
        $destination = $entry.Open()
        $source = [IO.File]::OpenRead($entries[$relative])
        try { $source.CopyTo($destination) } finally { $source.Dispose(); $destination.Dispose() }
    }
} finally { $archive.Dispose() }

$sha = (Get-FileHash -Algorithm SHA256 -LiteralPath $target).Hash
$compatibilityPath = Join-Path $root 'compatibility/versions.json'
$compatibility = Get-Content -LiteralPath $compatibilityPath -Raw | ConvertFrom-Json
$compatibility.package = [pscustomobject]@{ file = 'release/Panel Plugin/' + (Split-Path $target -Leaf); sha256 = $sha }
[IO.File]::WriteAllText($compatibilityPath, (ConvertTo-Json -InputObject $compatibility -Depth 100) + "`n", [Text.UTF8Encoding]::new($false))

$readme = "# iByte Minecraft Manager $version`n`nImport this ZIP through Pelican Admin > Plugins and install/update it. No Wings changes are needed.`n`nTake a backup before installing. The reminder is advisory. Failed/cancelled installs keep partial files/startup and release their slot; no automatic rollback is performed.`n`nDownloads and extraction run on Wings. The Panel reads small manifest/index JSON and filesystem metadata, with completion/expected-size checks rather than full-payload checksum verification. Published hashes remain expected metadata. Temporary operation folders are cleaned afterward.`n`nThis package's version is $version. Packaging does not assert runtime or browser acceptance. Already-running workers retain loaded classes until refreshed.`n"
Set-Content -LiteralPath (Join-Path $outputDirectory 'README.md') -Value $readme -NoNewline
Write-Output "Release: $(Split-Path $target -Leaf)"
Write-Output "SHA-256: $sha"
Write-Output "$($entries.Count) files packaged."
