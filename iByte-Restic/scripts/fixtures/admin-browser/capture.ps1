param([Parameter(Mandatory=$true)][string]$OutputPath, [int]$Width=1600, [int]$Height=1100)
$ErrorActionPreference = 'Stop'
$targets = Invoke-RestMethod -Uri http://127.0.0.1:9228/json/list
$target = @($targets | ForEach-Object { $_ } | Where-Object { $_.type -eq 'page' -and $_.url.StartsWith('http://127.0.0.1:8099/') })[0]
if (!$target) { throw 'No disposable admin fixture browser target.' }
$socket = [Net.WebSockets.ClientWebSocket]::new()
$cancel = [Threading.CancellationTokenSource]::new(30000)
[void]$socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, $cancel.Token).GetAwaiter().GetResult()
function Invoke-FixtureProtocol([int]$MessageId, [string]$Method, [hashtable]$Parameters) {
    $payload = @{id=$MessageId;method=$Method;params=$Parameters} | ConvertTo-Json -Compress -Depth 10
    $bytes = [Text.Encoding]::UTF8.GetBytes($payload)
    [void]$socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancel.Token).GetAwaiter().GetResult()
    $buffer = [byte[]]::new(1048576)
    do {
        $message = [Text.StringBuilder]::new()
        do {
            $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $cancel.Token).GetAwaiter().GetResult()
            [void]$message.Append([Text.Encoding]::UTF8.GetString($buffer, 0, $received.Count))
        } while (!$received.EndOfMessage)
        $response = $message.ToString() | ConvertFrom-Json
    } while ($response.id -ne $MessageId)
    if ($response.error) { throw ($response.error | ConvertTo-Json -Compress) }
    return $response.result
}
try {
    Invoke-FixtureProtocol 1 'Emulation.setDeviceMetricsOverride' @{width=$Width;height=$Height;deviceScaleFactor=1;mobile=$false} | Out-Null
    Invoke-FixtureProtocol 2 'Runtime.evaluate' @{expression='new Promise(r=>setTimeout(r,250))';awaitPromise=$true} | Out-Null
    $screenshot = Invoke-FixtureProtocol 3 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$false}
    $resolvedOutput = [IO.Path]::GetFullPath((Join-Path $PWD $OutputPath))
    $fixtureBuild = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../../.build')) + [IO.Path]::DirectorySeparatorChar
    if (!$resolvedOutput.StartsWith($fixtureBuild, [StringComparison]::OrdinalIgnoreCase)) { throw 'Screenshots must stay under this workspace .build directory.' }
    [IO.File]::WriteAllBytes($resolvedOutput, [Convert]::FromBase64String($screenshot.data))
    Write-Output ('Saved fixture screenshot: '+$resolvedOutput)
} finally {
    $socket.Dispose()
    $cancel.Dispose()
}
