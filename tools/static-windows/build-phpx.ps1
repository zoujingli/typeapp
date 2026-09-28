param(
    [Parameter(Mandatory = $true)][string]$PhpSource,
    [Parameter(Mandatory = $true)][string]$PhpxSource,
    [Parameter(Mandatory = $true)][string]$PhpArchive,
    [Parameter(Mandatory = $true)][string]$DependenciesDirectory,
    [Parameter(Mandatory = $true)][string]$Directory
)
$ErrorActionPreference = 'Stop'
# 调用方先核对固定源码、适配和依赖身份。本入口只构建 PHPX，不生成应用候选。
if ($env:OS -ne 'Windows_NT' -or ![IO.Path]::IsPathFullyQualified($Directory) -or (Test-Path -LiteralPath $Directory)) {
    throw 'PHPX 静态实验需要 Windows x64 和新的任务目录。'
}
$taskEvidence = Join-Path $Directory 'evidence'
New-Item -ItemType Directory -Path $taskEvidence -Force | Out-Null
$taskDecimal = Join-Path $PhpxSource 'thirdparty/mpdecimal'
foreach ($taskPart in @('libmpdec', 'libmpdec++')) {
    $taskPartDirectory = Join-Path $taskDecimal $taskPart
    Copy-Item -LiteralPath (Join-Path $taskPartDirectory 'Makefile.vc') -Destination (Join-Path $taskPartDirectory 'Makefile')
    if ($taskPart -eq 'libmpdec') {
        # 上游发行树可能携带 Unix 生成头；明确使用同版官方 Windows x64 头。
        Copy-Item -LiteralPath (Join-Path $taskPartDirectory 'mpdecimal64vc.h') -Destination (Join-Path $taskPartDirectory 'mpdecimal.h')
    }
    Push-Location $taskPartDirectory
    try {
        $taskTarget = if ($taskPart -eq 'libmpdec') { 'libmpdec-4.0.1.lib' } else { 'libmpdec++-4.0.1.lib' }
        & nmake /nologo /f Makefile 'MACHINE=x64' 'CC=cl' 'CXX=cl' $taskTarget 2>&1 |
            Tee-Object -FilePath (Join-Path $taskEvidence ($taskPart + '.log'))
        if ($LASTEXITCODE -ne 0) { throw ('官方 mpdecimal 静态目标失败：' + $taskPart) }
    } finally { Pop-Location }
}
$taskBuild = Join-Path $Directory 'build'
& cmake -S (Join-Path $PSScriptRoot 'phpx') -B $taskBuild -G 'Visual Studio 17 2022' -A x64 `
    "-DPHPX_ROOT=$PhpxSource" "-DPHP_SOURCE=$PhpSource" "-DDEPENDENCY_ROOT=$DependenciesDirectory" `
    2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'configure.log')
if ($LASTEXITCODE -ne 0) { throw 'PHPX 静态配置失败。' }
& cmake --build $taskBuild --config Release --target phpx --parallel 2 `
    2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'build.log')
if ($LASTEXITCODE -ne 0) { throw 'PHPX 完整 SAPI 静态编译失败。' }
$taskPhpxArchive = Join-Path $taskBuild 'lib/Release/phpx.lib'
$taskMpdecArchive = Join-Path $taskDecimal 'libmpdec/libmpdec-4.0.1.lib'
$taskMpdecxxArchive = Join-Path $taskDecimal 'libmpdec++/libmpdec++-4.0.1.lib'
$taskLibraries = @($taskPhpxArchive, $PhpArchive, $taskMpdecxxArchive, $taskMpdecArchive) +
    @(Get-ChildItem -LiteralPath (Join-Path $DependenciesDirectory 'lib') -Filter '*.lib' -File | Sort-Object Name | ForEach-Object { $_.FullName })
$taskProgram = Join-Path $Directory 'phpx-probe.exe'
& cl.exe /nologo /MT /EHsc /O2 /std:c++20 /utf-8 /Zc:__cplusplus /Zc:preprocessor /D NOMINMAX `
    /D PHP_WIN32=1 /D ZEND_WIN32=1 /D ZTS=1 /D ZEND_DEBUG=0 /D ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 /D ENABLE_INTSAFE_SIGNED_FUNCTIONS `
    "/I$PhpSource" "/I$PhpSource/main" "/I$PhpSource/Zend" "/I$PhpSource/TSRM" "/I$PhpSource/ext" `
    "/I$PhpxSource/include" "/I$PhpxSource/thirdparty/wren-gc/include" "/I$DependenciesDirectory/include" `
    "/I$taskDecimal/libmpdec" "/I$taskDecimal/libmpdec++" (Join-Path $PSScriptRoot 'phpx-probe.cpp') `
    "/Fo$Directory/phpx-probe.obj" "/Fe$taskProgram" /link @taskLibraries `
    kernel32.lib user32.lib advapi32.lib shell32.lib ws2_32.lib ole32.lib oleaut32.lib dnsapi.lib psapi.lib bcrypt.lib `
    pathcch.lib iphlpapi.lib crypt32.lib normaliz.lib secur32.lib wldap32.lib winmm.lib synchronization.lib `
    2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'link.log')
if ($LASTEXITCODE -ne 0) { throw 'PHPX 与静态 PHP 的真实链接失败。' }
$taskImports = & dumpbin.exe /nologo /dependents $taskProgram
if ($LASTEXITCODE -ne 0) { throw 'PHPX 探针的 PE 审计失败。' }
$taskImports | Set-Content -LiteralPath (Join-Path $taskEvidence 'imports.txt') -Encoding utf8
$taskDlls = @([regex]::Matches(($taskImports -join "`n"), '(?im)^\s+([A-Za-z0-9_.-]+\.dll)\s*$') | ForEach-Object { $_.Groups[1].Value.ToLowerInvariant() })
$taskAllowed = @('kernel32.dll', 'user32.dll', 'advapi32.dll', 'shell32.dll', 'ws2_32.dll', 'ole32.dll', 'oleaut32.dll',
    'dnsapi.dll', 'psapi.dll', 'bcrypt.dll', 'iphlpapi.dll', 'crypt32.dll', 'normaliz.dll', 'secur32.dll', 'wldap32.dll',
    'winmm.dll', 'api-ms-win-core-path-l1-1-0.dll', 'api-ms-win-core-synch-l1-2-0.dll')
if (!$taskDlls.Count -or @($taskDlls | Where-Object { $_ -notin $taskAllowed }).Count) { throw 'PHPX 探针仍依赖非系统 DLL。' }
$taskDeploy = Join-Path $Directory 'program only'
New-Item -ItemType Directory -Path (Join-Path $taskDeploy 'empty') -Force | Out-Null
Copy-Item -LiteralPath $taskProgram -Destination (Join-Path $taskDeploy 'probe.exe')
Set-Content -LiteralPath (Join-Path $taskDeploy 'php.ini') -Value 'display_errors=1' -Encoding utf8
$taskPreviousPath = $env:PATH
try {
    $env:PATH = Join-Path $env:SystemRoot 'System32'
    & (Join-Path $taskDeploy 'probe.exe') (Join-Path $taskDeploy 'php.ini') (Join-Path $taskDeploy 'empty') `
        2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'runtime.log')
    if ($LASTEXITCODE -ne 0) { throw 'PHPX 静态值操作或请求生命周期失败。' }
} finally { $env:PATH = $taskPreviousPath }
Copy-Item -LiteralPath $taskProgram -Destination (Join-Path $taskEvidence 'phpx-probe.exe')
@{passed=$true; scope='PHPX static probe only; no application acceptance';
    program_sha256=(Get-FileHash -LiteralPath $taskProgram -Algorithm SHA256).Hash.ToLowerInvariant();
    system_libraries=$taskDlls; archives=@($taskLibraries | ForEach-Object {
        @{file=[IO.Path]::GetFileName($_); sha256=(Get-FileHash -LiteralPath $_ -Algorithm SHA256).Hash.ToLowerInvariant()}
    })} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $taskEvidence 'verification.json') -Encoding utf8
