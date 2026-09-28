param([Parameter(Mandatory = $true)][string]$Directory)
$ErrorActionPreference = 'Stop'
# 本入口只制备和验证第三方静态依赖，不创建 PHP SDK 或应用候选。
if ($env:OS -ne 'Windows_NT' -or ![IO.Path]::IsPathFullyQualified($Directory) -or (Test-Path -LiteralPath $Directory)) {
    throw '需要 Windows x64 和尚不存在的绝对工作目录。'
}
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskWork = [IO.Path]::GetFullPath($Directory)
$taskInput = Join-Path $PSScriptRoot 'static-windows'
$taskEvidence = Join-Path $taskWork 'evidence'
New-Item -ItemType Directory -Path $taskEvidence -Force | Out-Null
$taskManifest = Get-Content -Raw -LiteralPath (Join-Path $taskInput 'vcpkg.json') | ConvertFrom-Json
$taskReference = $taskManifest.'builtin-baseline'
if ($taskReference -cnotmatch '^[a-f0-9]{40}$') { throw 'vcpkg 必须固定完整来源提交。' }
$taskVcpkg = Join-Path $taskWork 'vcpkg'
& git init -q $taskVcpkg
if ($LASTEXITCODE -ne 0) { throw '无法创建任务内 vcpkg 目录。' }
& git -C $taskVcpkg remote add origin https://github.com/microsoft/vcpkg.git
& git -C $taskVcpkg fetch --depth 1 origin $taskReference
if ($LASTEXITCODE -ne 0) { throw 'vcpkg 固定源码获取失败。' }
& git -C $taskVcpkg checkout --detach FETCH_HEAD
if ($LASTEXITCODE -ne 0 -or (& git -C $taskVcpkg rev-parse HEAD) -cne $taskReference) { throw 'vcpkg 源码身份不符。' }
& (Join-Path $taskVcpkg 'bootstrap-vcpkg.bat') -disableMetrics 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'bootstrap.log')
if ($LASTEXITCODE -ne 0) { throw 'vcpkg 启动失败。' }
$taskInstalled = Join-Path $taskWork 'installed'
$env:VCPKG_MAX_CONCURRENCY = '2'
& (Join-Path $taskVcpkg 'vcpkg.exe') install "--x-manifest-root=$taskInput" "--x-install-root=$taskInstalled" `
    "--overlay-triplets=$taskInput" --triplet=x64-typeapp-static --host-triplet=x64-typeapp-static --disable-metrics `
    2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'dependencies.log')
if ($LASTEXITCODE -ne 0) { throw '固定依赖静态构建失败。' }

$taskVswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio/Installer/vswhere.exe'
$taskVs = & $taskVswhere -latest -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if ($LASTEXITCODE -ne 0 -or !$taskVs) { throw '无法定位 MSVC x64。' }
$taskVariables = & $env:ComSpec /d /s /c ('call "' + $taskVs + '\Common7\Tools\VsDevCmd.bat" -arch=x64 -host_arch=x64 >nul && set')
if ($LASTEXITCODE -ne 0) { throw 'MSVC 环境初始化失败。' }
foreach ($taskLine in $taskVariables) {
    if ($taskLine -match '^([^=]+)=(.*)$' -and $matches[1] -in @('PATH', 'VCToolsInstallDir', 'WindowsSdkDir', 'WindowsSDKVersion', 'INCLUDE', 'LIB', 'LIBPATH')) {
        [Environment]::SetEnvironmentVariable($matches[1], $matches[2], 'Process')
    }
}
$taskPrefix = Join-Path $taskInstalled 'x64-typeapp-static'
$taskLibraries = @(Get-ChildItem -LiteralPath (Join-Path $taskPrefix 'lib') -Filter '*.lib' -File | Sort-Object Name | ForEach-Object { $_.FullName })
if (!$taskLibraries.Count -or @(Get-ChildItem -LiteralPath $taskPrefix -Filter '*.dll' -File -Recurse).Count) {
    throw '目标依赖为空或仍携带非系统 DLL。'
}
$taskProgram = Join-Path $taskWork 'dependencies-probe.exe'
& cl.exe /nologo /MT /EHsc /O2 /std:c++17 "/I$taskPrefix/include" "/I$taskPrefix/include/libxml2" "/I$taskPrefix/include/postgresql" `
    (Join-Path $taskInput 'dependencies-probe.cpp') "/Fo$taskWork/dependencies-probe.obj" "/Fe$taskProgram" /link @taskLibraries `
    crypt32.lib bcrypt.lib ws2_32.lib advapi32.lib user32.lib normaliz.lib iphlpapi.lib secur32.lib wldap32.lib shell32.lib ole32.lib `
    2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'link.log')
if ($LASTEXITCODE -ne 0) { throw '第三方静态依赖真实链接失败。' }
$taskImports = & dumpbin.exe /nologo /dependents $taskProgram
if ($LASTEXITCODE -ne 0) { throw '依赖探针 PE 导入审计失败。' }
$taskImports | Set-Content -LiteralPath (Join-Path $taskEvidence 'imports.txt') -Encoding utf8
$taskDlls = @([regex]::Matches(($taskImports -join "`n"), '(?im)^\s+([A-Za-z0-9_.-]+\.dll)\s*$') | ForEach-Object { $_.Groups[1].Value.ToLowerInvariant() })
$taskAllowed = @('kernel32.dll', 'advapi32.dll', 'ws2_32.dll', 'user32.dll', 'shell32.dll', 'ole32.dll', 'oleaut32.dll', 'shlwapi.dll', 'dnsapi.dll', 'iphlpapi.dll', 'bcrypt.dll', 'normaliz.dll', 'crypt32.dll', 'psapi.dll', 'secur32.dll', 'wldap32.dll')
if (!$taskDlls.Count -or @($taskDlls | Where-Object { $_ -notin $taskAllowed }).Count) { throw '依赖探针仍导入非系统 DLL。' }
$taskDeploy = Join-Path $taskWork 'program only'
New-Item -ItemType Directory -Path $taskDeploy | Out-Null
Copy-Item -LiteralPath $taskProgram -Destination (Join-Path $taskDeploy 'probe.exe')
$taskOldPath = $env:PATH
try {
    $env:PATH = Join-Path $env:SystemRoot 'System32'
    & (Join-Path $taskDeploy 'probe.exe') 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'runtime.log')
    if ($LASTEXITCODE -ne 0) { throw '静态依赖在独立程序目录运行失败。' }
} finally { $env:PATH = $taskOldPath }
$taskIdentity = @($taskLibraries | ForEach-Object { @{file=[IO.Path]::GetFileName($_); sha256=(Get-FileHash -LiteralPath $_ -Algorithm SHA256).Hash.ToLowerInvariant()} })
@{passed=$true; scope='third-party dependencies only'; source=$taskReference; triplet='x64-typeapp-static'; libraries=$taskIdentity;
    manifest_sha256=(Get-FileHash -LiteralPath (Join-Path $taskInput 'vcpkg.json') -Algorithm SHA256).Hash.ToLowerInvariant();
    triplet_sha256=(Get-FileHash -LiteralPath (Join-Path $taskInput 'x64-typeapp-static.cmake') -Algorithm SHA256).Hash.ToLowerInvariant();
    program_sha256=(Get-FileHash -LiteralPath $taskProgram -Algorithm SHA256).Hash.ToLowerInvariant(); system_libraries=$taskDlls} |
    ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $taskEvidence 'verification.json') -Encoding utf8
Write-Host 'Windows 第三方静态依赖已完成真实链接与独立运行，尚不代表 PHP/Swoole/应用验收。'
