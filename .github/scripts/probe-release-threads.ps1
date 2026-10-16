Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$taskOutput = Join-Path $taskRoot 'build/release-blockers-threads'
[IO.Directory]::CreateDirectory($taskOutput) | Out-Null
$env:COMPOSER_DISABLE_NETWORK = '1'
$env:COMPOSER_CACHE_DIR = Join-Path $taskRoot '.cache/composer'
$taskInfo = [Diagnostics.ProcessStartInfo]::new()
$taskInfo.FileName = Join-Path $env:PHP_HOME 'php.exe'
$taskInfo.WorkingDirectory = $taskRoot
$taskInfo.UseShellExecute = $false
$taskInfo.RedirectStandardOutput = $true
$taskInfo.RedirectStandardError = $true
$taskInfo.ArgumentList.Add('tests/compiled-threads.php')
$taskInfo.ArgumentList.Add(($taskRoot.Replace('\', '/') + '/build/release-blockers-thread-consumer'))
$taskProcess = [Diagnostics.Process]::Start($taskInfo)
$taskStdout = $taskProcess.StandardOutput.ReadToEndAsync()
$taskStderr = $taskProcess.StandardError.ReadToEndAsync()
$taskWatch = [Diagnostics.Stopwatch]::StartNew()
$taskSamples = [Collections.Generic.List[object]]::new()
try {
    while (!$taskProcess.WaitForExit(30000)) {
        $taskRows = @(Get-Process -Name php,cl,c1xx,link,mspdbsrv -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,CPU,WorkingSet64,Handles)
        $taskObjects = @(Get-ChildItem -LiteralPath (Join-Path $taskRoot 'build/release-blockers-thread-consumer') -Recurse -Filter '*.obj' -ErrorAction SilentlyContinue)
        $taskSamples.Add(@{ seconds=[Math]::Round($taskWatch.Elapsed.TotalSeconds, 3); processes=$taskRows; objects=$taskObjects.Count })
        $taskSamples | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $taskOutput 'progress.json') -Encoding utf8
        if ($taskWatch.Elapsed.TotalSeconds -gt 1000) { throw '原预算后的外层诊断保护触发。' }
    }
    [IO.File]::WriteAllText((Join-Path $taskOutput 'stdout.log'), $taskStdout.GetAwaiter().GetResult())
    [IO.File]::WriteAllText((Join-Path $taskOutput 'stderr.log'), $taskStderr.GetAwaiter().GetResult())
    @{ exit=$taskProcess.ExitCode; seconds=$taskWatch.Elapsed.TotalSeconds; source=$env:GITHUB_SHA } | ConvertTo-Json | Set-Content (Join-Path $taskOutput 'verification.json') -Encoding utf8
    if ($taskProcess.ExitCode -ne 0) { throw '原预算线程复演失败；保留进程与生成代码证据。' }
} finally {
    if (!$taskProcess.HasExited) { $taskProcess.Kill($true); $taskProcess.WaitForExit(10000) | Out-Null }
    $taskProcess.Dispose()
}
