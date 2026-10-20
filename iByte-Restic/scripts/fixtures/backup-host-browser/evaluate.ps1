param([Parameter(Mandatory=$true)][string]$ExpressionFile, [string]$TargetUrlPrefix = '', [int]$Width=0, [int]$Height=844)
$ErrorActionPreference = 'Stop'
$targets = Invoke-RestMethod -Uri http://127.0.0.1:9228/json/list
$target = @($targets | ForEach-Object { $_ } | Where-Object { $_.type -eq 'page' -and (!$TargetUrlPrefix -or $_.url.StartsWith($TargetUrlPrefix)) })[0]
if (!$target) { throw 'No matching disposable browser page target.' }
$socket = [Net.WebSockets.ClientWebSocket]::new()
$cancel = [Threading.CancellationTokenSource]::new(30000)
[void]$socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, $cancel.Token).GetAwaiter().GetResult()
if ($Width -gt 0) {
    $emulation = @{id=0;method='Emulation.setDeviceMetricsOverride';params=@{width=$Width;height=$Height;deviceScaleFactor=1;mobile=$false}} | ConvertTo-Json -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($emulation)
    [void]$socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancel.Token).GetAwaiter().GetResult()
    $temporary = [byte[]]::new(1048576)
    do {
        $reply = $socket.ReceiveAsync([ArraySegment[byte]]::new($temporary), $cancel.Token).GetAwaiter().GetResult()
        $ack = [Text.Encoding]::UTF8.GetString($temporary, 0, $reply.Count) | ConvertFrom-Json
    } while ($ack.id -ne 0)
    if ($ack.error) { throw ($ack.error | ConvertTo-Json -Compress) }
}
$expression = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $ExpressionFile)).Trim().TrimEnd(';')
if ($Width -gt 0) { $expression = '(async()=>{await new Promise(r=>setTimeout(r,250));return ('+$expression+');})()' }
$payload = @{id=1;method='Runtime.evaluate';params=@{expression=$expression;awaitPromise=$true;returnByValue=$true}} | ConvertTo-Json -Compress -Depth 10
$sendBytes = [Text.Encoding]::UTF8.GetBytes($payload)
[void]$socket.SendAsync([ArraySegment[byte]]::new($sendBytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancel.Token).GetAwaiter().GetResult()
$buffer = [byte[]]::new(1048576)
do {
    $message = [Text.StringBuilder]::new()
    do {
        $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $cancel.Token).GetAwaiter().GetResult()
        [void]$message.Append([Text.Encoding]::UTF8.GetString($buffer, 0, $received.Count))
    } while (!$received.EndOfMessage)
    $response = $message.ToString() | ConvertFrom-Json
} while ($response.id -ne 1)
$response | ConvertTo-Json -Compress -Depth 25
$socket.Dispose()
$cancel.Dispose()
if ($response.error -or $response.result.exceptionDetails) { throw 'Browser protocol or JavaScript assertion failed.' }
