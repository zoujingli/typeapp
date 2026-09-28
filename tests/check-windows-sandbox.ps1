param([string]$Directory)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
if (!$Directory) { $Directory = Join-Path $taskRoot ('build/windows-sandbox-' + [Guid]::NewGuid().ToString('N')) }
if (Test-Path -LiteralPath $Directory) { throw '隔离测试必须使用新目录。' }
$taskWork = [IO.Path]::GetFullPath($Directory)
$taskVswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio/Installer/vswhere.exe'
$taskVs = & $taskVswhere -latest -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if ($LASTEXITCODE -ne 0 -or !$taskVs) { throw '无法定位 MSVC。' }
$taskSetup = 'call "' + $taskVs + '\Common7\Tools\VsDevCmd.bat" -arch=x64 -host_arch=x64 >nul && set'
$taskVariables = & $env:ComSpec /d /s /c $taskSetup
if ($LASTEXITCODE -ne 0) { throw 'MSVC 环境初始化失败。' }
foreach ($taskLine in $taskVariables) {
    if ($taskLine -match '^([^=]+)=(.*)$' -and $matches[1] -in @('PATH', 'INCLUDE', 'LIB', 'LIBPATH')) {
        [Environment]::SetEnvironmentVariable($matches[1], $matches[2], 'Process')
    }
}
New-Item -ItemType Directory -Path $taskWork | Out-Null
$taskRunner = Join-Path $taskWork 'restricted-runner.exe'
& cl.exe /nologo /MT /EHsc /std:c++17 /utf-8 (Join-Path $PSScriptRoot 'native-windows-sandbox.cpp') "/Fo$taskWork/runner.obj" "/Fe$taskRunner" /link advapi32.lib
if ($LASTEXITCODE -ne 0) { throw 'Windows 原生隔离设置器编译失败。' }
$taskProgram = Join-Path $taskWork '程序 program only'
$taskData = Join-Path $taskWork '数据 runtime data'
New-Item -ItemType Directory -Path $taskProgram, $taskData | Out-Null
Copy-Item -LiteralPath $taskRunner -Destination (Join-Path $taskProgram 'app.exe')
$taskCompiler = (Get-Command cl.exe -ErrorAction Stop).Source
$taskNode = (Get-Command node.exe -ErrorAction Stop).Source
$taskBlocked = @((Join-Path $taskRoot 'app/main.php'), $taskCompiler, $taskNode)
$taskSid = 'S-1-5-21-' + ((1..3 | ForEach-Object { Get-Random -Minimum 100000000 -Maximum 2000000000 }) -join '-') + '-12345'
$taskSpec = Join-Path $taskWork 'specification.json'
@{sid=$taskSid; changes=@(
    @{path=$taskRoot; access='deny-read'},
    @{path=(Split-Path $taskCompiler -Parent); access='deny-read'},
    @{path=(Split-Path $taskNode -Parent); access='deny-read'},
    @{path=$taskProgram; access='read'},
    @{path=$taskRunner; access='read'},
    @{path=$taskData; access='modify'}
)} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $taskSpec -Encoding utf8
$taskPowershell = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
# 通过普通子进程继承环境，与 PHP proc_open 一致；pwsh 的原生命令调用会改写旧版 PSModulePath。
function Invoke-TaskSystemPowerShell([string[]]$TaskArguments) {
    $taskStart = [Diagnostics.ProcessStartInfo]::new()
    $taskStart.FileName = $taskPowershell
    $taskStart.UseShellExecute = $false
    foreach ($taskArgument in $TaskArguments) { $taskStart.ArgumentList.Add($taskArgument) }
    $taskProcess = [Diagnostics.Process]::Start($taskStart)
    try {
        if (!$taskProcess.WaitForExit(120000)) {
            $taskProcess.Kill($true)
            $taskProcess.WaitForExit()
            throw '系统 PowerShell 隔离操作超时。'
        }
        return $taskProcess.ExitCode
    } finally {
        $taskProcess.Dispose()
    }
}
try {
    $taskOriginal = @($taskBlocked | ForEach-Object {
        @{path=$_; sddl=(Get-Acl -LiteralPath $_).GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)}
    })
    # 与 PHP 应用验收使用同一系统解释器，不能用 pwsh 绕过 5.1 的编码边界。
    $taskExit = Invoke-TaskSystemPowerShell @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'native-windows-sandbox.ps1'), 'prepare', $taskSpec)
    if ($taskExit -ne 0) { throw '系统 PowerShell 隔离准备失败。' }
    $taskRestricted = @($taskBlocked | ForEach-Object {
        @{path=$_; sddl=(Get-Acl -LiteralPath $_).GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)}
    })
    @{sid=$taskSid; original=$taskOriginal; restricted=$taskRestricted} | ConvertTo-Json -Depth 5 |
        Set-Content -LiteralPath (Join-Path $taskWork 'probe-inputs.json') -Encoding utf8
    # 父进程仍可读相同文件，排除改坏全局权限或以缺失文件制造假拒绝。
    foreach ($taskFile in $taskBlocked) {
        $taskStream = [IO.File]::OpenRead($taskFile)
        $taskStream.Dispose()
    }
    & $taskRunner $taskSid $taskRunner --probe (Join-Path $taskProgram 'app.exe') $taskData $taskProgram `
        @taskBlocked
    if ($LASTEXITCODE -ne 0) { throw '受限令牌未同时满足读取、写入与拒绝探针。' }
} finally {
    $taskExit = Invoke-TaskSystemPowerShell @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'native-windows-sandbox.ps1'), 'restore', $taskSpec)
    if ($taskExit -ne 0) { throw '系统 PowerShell 隔离恢复失败。' }
}
@{passed=$true; runner_sha256=(Get-FileHash -LiteralPath $taskRunner -Algorithm SHA256).Hash.ToLowerInvariant();
    checks=@('source-read-denied','compiler-read-denied','node-read-denied','source-execute-denied','directory-metadata-readable','program-readable','program-readonly','data-writable','controller-unaffected','powershell-5.1','inherited-module-environment','unicode-paths','acl-restored')} |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $taskWork 'verification.json') -Encoding utf8
