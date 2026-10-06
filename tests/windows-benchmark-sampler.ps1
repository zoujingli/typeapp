param([Parameter(Mandatory)][int]$TargetProcessId, [Parameter(Mandatory)][string]$Directory)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$taskDirectory = [IO.Path]::GetFullPath($Directory)
if ($TargetProcessId -le 0 -or !(Test-Path -LiteralPath $taskDirectory -PathType Container)) { throw '采样需要实际进程与本轮专用目录。' }
$taskRootProcess = [Diagnostics.Process]::GetProcessById($TargetProcessId)
try { $taskStartedTicks = $taskRootProcess.StartTime.ToUniversalTime().Ticks } finally { $taskRootProcess.Dispose() }
$taskRequest = Join-Path $taskDirectory 'request'
$taskStop = Join-Path $taskDirectory 'stop'
$taskClock = [Diagnostics.Stopwatch]::StartNew()
$taskSamples = 0
[IO.File]::WriteAllText((Join-Path $taskDirectory 'ready.pending'), [string]$TargetProcessId, [Text.UTF8Encoding]::new($false))
[IO.File]::Move((Join-Path $taskDirectory 'ready.pending'), (Join-Path $taskDirectory 'ready'))
while (!(Test-Path -LiteralPath $taskStop)) {
    if ($taskClock.Elapsed.TotalSeconds -gt 1800) { throw 'Windows 资源采样超过本轮有界期限。' }
    if (!(Test-Path -LiteralPath $taskRequest)) { Start-Sleep -Milliseconds 2; continue }
    $taskRequestId = [IO.File]::ReadAllText($taskRequest)
    if ($taskRequestId -notmatch '^[a-f0-9]{12}$') { throw '资源采样请求身份无效。' }
    $taskCapture = [Diagnostics.Stopwatch]::StartNew()
    # CIM 只发现父子关系；CPU 与工作集均来自被测进程的 System.Diagnostics 实际计数。
    $taskRows = @(Get-CimInstance -ClassName Win32_Process -Property ProcessId, ParentProcessId)
    if (!($taskRows | Where-Object { [int]$_.ProcessId -eq $TargetProcessId })) { throw '父子进程快照缺少被测根进程。' }
    $taskSelected = [Collections.Generic.HashSet[int]]::new()
    [void]$taskSelected.Add($TargetProcessId)
    do {
        $taskCount = $taskSelected.Count
        foreach ($taskRow in $taskRows) {
            if ($taskSelected.Contains([int]$taskRow.ParentProcessId)) { [void]$taskSelected.Add([int]$taskRow.ProcessId) }
        }
    } while ($taskSelected.Count -ne $taskCount)
    [long]$taskRss = 0
    [double]$taskCpu = 0
    foreach ($taskProcessId in $taskSelected) {
        $taskObserved = [Diagnostics.Process]::GetProcessById($taskProcessId)
        try {
            $taskObserved.Refresh()
            if ($taskObserved.HasExited -or ($taskProcessId -eq $TargetProcessId -and $taskObserved.StartTime.ToUniversalTime().Ticks -ne $taskStartedTicks)) { throw '采样期间被测进程退出或身份变化。' }
            $taskRss += $taskObserved.WorkingSet64
            $taskCpu += $taskObserved.TotalProcessorTime.TotalSeconds
        } finally { $taskObserved.Dispose() }
    }
    $taskCapture.Stop()
    $taskSample = @{ request=$taskRequestId; pid=$TargetProcessId; rss_bytes=$taskRss; cpu_seconds=$taskCpu; processes=$taskSelected.Count; capture_seconds=$taskCapture.Elapsed.TotalSeconds }
    $taskTemporary = Join-Path $taskDirectory ($taskRequestId + '.pending')
    [IO.File]::WriteAllText($taskTemporary, ($taskSample | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Remove-Item -LiteralPath $taskRequest
    [IO.File]::Move($taskTemporary, (Join-Path $taskDirectory ($taskRequestId + '.json')))
    $taskSamples++
}
@{status='stopped'; pid=$TargetProcessId; samples=$taskSamples; elapsed_seconds=$taskClock.Elapsed.TotalSeconds} | ConvertTo-Json -Compress
