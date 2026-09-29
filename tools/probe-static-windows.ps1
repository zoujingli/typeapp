param(
    [Parameter(Mandatory = $true)][string]$Directory,
    [string]$DependenciesDirectory = '',
    [string]$DependencyVerification = '',
    [switch]$WithPhpx,
    [ValidateSet('sqlite', 'mysql', 'pgsql', 'all')][string]$Profile = 'all'
)
$ErrorActionPreference = 'Stop'
# 分别验证静态 PHP 核心或完整扩展组合；本入口不生成应用候选，也不修改共享 SDK。
if ($env:OS -ne 'Windows_NT' -or ![IO.Path]::IsPathFullyQualified($Directory) -or (Test-Path -LiteralPath $Directory)) {
    throw '需要 Windows x64 和尚不存在的绝对工作目录。'
}
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskFeatures = ''
$taskRedisEnabled = $false
$taskWork = [IO.Path]::GetFullPath($Directory)
New-Item -ItemType Directory -Path $taskWork | Out-Null
$taskEvidence = Join-Path $taskWork 'evidence'
New-Item -ItemType Directory -Path $taskEvidence | Out-Null
$taskUtf8 = [Text.UTF8Encoding]::new($false)

function Write-StaticStage {
    param([string]$Stage)
    $taskMessage = [DateTime]::UtcNow.ToString('o') + ' ' + $Stage
    $taskMessage | Add-Content -LiteralPath (Join-Path $taskEvidence 'stages.log') -Encoding utf8
    Write-Host $taskMessage
}
Write-StaticStage 'msvc: locating compiler'
$taskVswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio/Installer/vswhere.exe'
$taskVs = & $taskVswhere -latest -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if ($LASTEXITCODE -ne 0 -or !$taskVs) { throw '无法定位 MSVC x64。' }
$taskSetup = 'call "' + $taskVs + '\Common7\Tools\VsDevCmd.bat" -arch=x64 -host_arch=x64 >nul && set'
Write-StaticStage 'msvc: importing compiler environment'
$taskVariables = & $env:ComSpec /d /s /c $taskSetup
if ($LASTEXITCODE -ne 0) { throw 'MSVC 环境初始化失败。' }
foreach ($taskLine in $taskVariables) {
    if ($taskLine -match '^([^=]+)=(.*)$' -and $matches[1] -in @('PATH', 'VCToolsInstallDir', 'WindowsSdkDir', 'WindowsSDKVersion', 'INCLUDE', 'LIB', 'LIBPATH')) {
        [Environment]::SetEnvironmentVariable($matches[1], $matches[2], 'Process')
    }
}
Write-StaticStage 'msvc: ready'

function Get-StaticSource {
    param([string]$Url, [string]$Digest, [string]$Name)
    $taskArchive = Join-Path $taskWork $Name
    Write-StaticStage ('download: ' + $Name)
    & (Join-Path $env:SystemRoot 'System32/curl.exe') -fsSL --retry 2 --retry-all-errors --retry-max-time 300 --connect-timeout 20 --max-time 120 -o $taskArchive $Url
    if ($LASTEXITCODE -ne 0 -or (Get-FileHash -LiteralPath $taskArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Digest) {
        throw ('源码下载或摘要核对失败：' + $Name)
    }
    Write-StaticStage ('extract: ' + $Name + ' (' + (Get-Item -LiteralPath $taskArchive).Length + ' bytes)')
    # Windows 内置 tar 在固定 PHP XZ 源码上持续停顿；分开解码和展开，
    # 保留两个阶段的退出码与日志，不更换源码归档或跳过摘要核对。
    $taskSevenZip = (Get-Command '7z.exe' -ErrorAction Stop).Source
    & $taskSevenZip x -y -bd -bsp0 "-o$taskWork" $taskArchive 2>&1 |
        Tee-Object -FilePath (Join-Path $taskEvidence ($Name + '.extract.log'))
    if ($LASTEXITCODE -ne 0) { throw ('源码归档解码失败：' + $Name) }
    if ($Name.EndsWith('.tar.xz', [StringComparison]::Ordinal) -or $Name.EndsWith('.tar.gz', [StringComparison]::Ordinal)) {
        $taskTarArchive = $taskArchive.Substring(0, $taskArchive.Length - 3)
        Write-StaticStage ('extract tar: ' + [IO.Path]::GetFileName($taskTarArchive))
        & $taskSevenZip x -y -bd -bsp0 "-o$taskWork" $taskTarArchive 2>&1 |
            Tee-Object -FilePath (Join-Path $taskEvidence ($Name + '.tar.extract.log'))
        if ($LASTEXITCODE -ne 0) { throw ('源码 TAR 展开失败：' + $Name) }
        Remove-Item -LiteralPath $taskTarArchive
    }
    Write-StaticStage ('source ready: ' + $Name)
}
Get-StaticSource 'https://www.php.net/distributions/php-8.5.10.tar.xz' '6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957' 'php.tar.xz'
Get-StaticSource 'https://codeload.github.com/php/php-sdk-binary-tools/zip/1142e4abaf90ceb6cc25d983797b25cc948669cf' '083324ab6ad0f5b8727539d717600ab0f29a2fe84bd147095cf0b115a63a45c4' 'tools.zip'
$taskSource = Join-Path $taskWork 'php-8.5.10'
$taskTools = Join-Path $taskWork 'php-sdk-binary-tools-1142e4abaf90ceb6cc25d983797b25cc948669cf'
$env:PATH = (Join-Path $taskTools 'bin') + ';' + (Join-Path $taskTools 'msys2/usr/bin') + ';' + $env:PATH

# 官方 Windows 头文件将消费者声明为 DLL import。只改 PHP 自有 API 的声明，
# 不改变 Windows 系统 API；整棵源码仅用于本轮静态构建，逐文件记录前后身份。
$taskHeaders = @{
    'main/php.h' = @('PHPAPI', '1bd30980a442f3324c23d94950b26f66d83964b57c7b994e78203c3ffead1321')
    'main/SAPI.h' = @('SAPI_API', 'f57e9cae13c623d3114dcb8f9e4686015f68ddafab1c9a79143b4852c8a3a64f')
    'Zend/zend_config.w32.h' = @('ZEND_API', 'f1600b247b563bb6961e5a1f6ddf6ce30df902a76529a5484fd7afda13133911')
    'Zend/zend_virtual_cwd.h' = @('CWD_API', 'd6ecfad3c94d9386c2783306ecfe628ee3ad9324c4bc689f4fe5933800bd7764')
    'TSRM/TSRM.h' = @('TSRM_API', 'f5eaac861d68fa3e8b8b465a77412e78c05e12c6ec69619b06c0ce5f598cb588')
    'win32/codepage.h' = @('PW32CP', 'db777f09bd5e225ffd92547251453f1c2306e17a8cb626d56ea5ab6560d64476')
    'win32/ipc.h' = @('PHP_WIN32_IPC_API', '8601fc21c1df87c2a93a8765d480f38051bff161690328dff38cd22232eba716')
    'win32/ioutil.h' = @('PW32IO', 'dc67706a27fd688a3467af2939c806dc114e31ab951e2cfb4cfa456d899045e5')
    'win32/console.h' = @('PHP_WINUTIL_API', 'a7bbe88f14f371a3afe288d26f9785d5255716c4891466b548b1a4c11fa21b14')
    'win32/winutil.h' = @('PHP_WINUTIL_API', 'cfd4631d5c8755db592a2df7f60578bb27b44b4c59c828c0a942d17d3818e17d')
}
$taskAdaptations = @()
foreach ($taskName in $taskHeaders.Keys | Sort-Object) {
    $taskFile = Join-Path $taskSource $taskName
    $taskBefore = (Get-FileHash -LiteralPath $taskFile -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($taskBefore -ne $taskHeaders[$taskName][1]) { throw ('PHP 头文件原文不符：' + $taskName) }
    $taskText = [IO.File]::ReadAllText($taskFile)
    $taskPattern = '(?m)(#[\t ]*define[\t ]+' + $taskHeaders[$taskName][0] + '[\t ]+)__declspec\(dll(?:export|import)\)'
    if ([regex]::Matches($taskText, $taskPattern).Count -ne 2) { throw ('静态 API 适配位置不唯一：' + $taskName) }
    $taskStaticText = [regex]::Replace($taskText, $taskPattern, '$1')
    if ($taskName -eq 'Zend/zend_config.w32.h') {
        # ZEND_DLIMPORT 只用于 zend_stream.c 的 isatty 声明；/MT 的 CRT
        # 已声明静态版本，继续使用 dllimport 会产生 C2375 链接方式冲突。
        $taskImportPattern = '(?m)(#define[\t ]+ZEND_DLIMPORT[\t ]+)__declspec\(dllimport\)'
        if ([regex]::Matches($taskStaticText, $taskImportPattern).Count -ne 1) { throw 'CRT 导入声明位置不唯一。' }
        $taskStaticText = [regex]::Replace($taskStaticText, $taskImportPattern, '$1')
    }
    [IO.File]::WriteAllText($taskFile, $taskStaticText, $taskUtf8)
    $taskAdaptations += @{ file=$taskName; before=$taskBefore; after=(Get-FileHash -LiteralPath $taskFile -Algorithm SHA256).Hash.ToLowerInvariant() }
}
$taskConfig = Join-Path $taskSource 'win32/build/confutils.js'
$taskBefore = (Get-FileHash -LiteralPath $taskConfig -Algorithm SHA256).Hash.ToLowerInvariant()
if ($taskBefore -ne 'e1a67dd1b662e2be95e46b67269b276f99663f1cafa35fef17d1dc2f9deaad91') { throw 'PHP 构建配置原文不符。' }
$taskText = [IO.File]::ReadAllText($taskConfig)
$taskCrt = 'ADD_FLAG("CFLAGS", "/MD");'
if ([regex]::Matches($taskText, [regex]::Escape($taskCrt)).Count -ne 1) { throw 'CRT 选择位置不唯一。' }
[IO.File]::WriteAllText($taskConfig, $taskText.Replace($taskCrt, 'ADD_FLAG("CFLAGS", "/MT");'), $taskUtf8)
$taskAdaptations += @{ file='win32/build/confutils.js'; before=$taskBefore; after=(Get-FileHash -LiteralPath $taskConfig -Algorithm SHA256).Hash.ToLowerInvariant() }
# 同一个静态映像共用 Zend 的 TLS 缓存；移除 embed 原为独立 DLL 定义的副本。
# /Zc:inline 不再受 dllexport 强制保留定义，公共随机数种子函数须有独立符号。
$taskSourceEdits = @{
    # /MT 已将 CRT 链接到程序；只有 /MD 定义 _DLL，才存在可比较版本的外部 CRT 映像。
    'win32/winutil.c' = @('23a30e669025edfb337a48afeac8a0652c7f2d808c8d9702054338744d04c88e', "#if PHP_LINKER_MAJOR == 14`n`t/* Extend for other CRT if needed. */", "#if PHP_LINKER_MAJOR == 14 && defined(_DLL)`n`t/* Extend for other CRT if needed. */", 1)
    'sapi/embed/php_embed.c' = @('e92e1804ef203b5c857fb2e92b149f9f32a52c0ca7c52e6d5a6bd21a26bf64d5', 'ZEND_TSRMLS_CACHE_DEFINE()', '/* Static embed shares the Zend core TLS cache. */', 1)
    'ext/random/engine_xoshiro256starstar.c' = @('228bfbf756931b9ca646543a37e5e13ed0b80e11399676efd3a81ce3d0d63a6d', 'PHPAPI inline void', 'PHPAPI void', 2)
    'ext/random/engine_mt19937.c' = @('e789018f1e172ec356910fb66d9d50f13b780e05920e12d510395beaf96196ac', 'PHPAPI inline void', 'PHPAPI void', 1)
    'ext/random/engine_pcgoneseq128xslrr64.c' = @('e513cf2b33de520db95402a55d52a733aa90ff2f52cfcc8af00e3d8c2da649c2', 'PHPAPI inline void', 'PHPAPI void', 1)
}
foreach ($taskName in $taskSourceEdits.Keys | Sort-Object) {
    $taskFile = Join-Path $taskSource $taskName
    $taskEdit = $taskSourceEdits[$taskName]
    $taskBefore = (Get-FileHash -LiteralPath $taskFile -Algorithm SHA256).Hash.ToLowerInvariant()
    $taskText = [IO.File]::ReadAllText($taskFile)
    if ($taskBefore -ne $taskEdit[0] -or [regex]::Matches($taskText, [regex]::Escape($taskEdit[1])).Count -ne $taskEdit[3]) {
        throw ('PHP 静态符号适配原文不符：' + $taskName)
    }
    [IO.File]::WriteAllText($taskFile, $taskText.Replace($taskEdit[1], $taskEdit[2]), $taskUtf8)
    $taskAdaptations += @{file=$taskName; before=$taskBefore; after=(Get-FileHash -LiteralPath $taskFile -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$taskAdaptations | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $taskEvidence 'adaptations.json') -Encoding utf8
Write-StaticStage 'source adaptations: verified'

# RuntimeIni 固定关闭 OPcache；仅保留 PHP 8.5 必需核心，不编入未使用的 JIT。
$taskConfigure = @('--disable-all', '--disable-cli', '--disable-cgi', '--disable-phpdbg', '--disable-opcache-jit', '--enable-embed', '--enable-zts', '--with-mp=2')
$taskExtraLibraries = ''
$taskRuntime = $DependenciesDirectory -ne ''
$taskRequiredExtensions = @('filter', 'tokenizer', 'ctype', 'session', 'mbstring', 'PDO', 'sockets', 'openssl', 'curl', 'zlib', 'iconv', 'swoole')
$taskDatabaseFlags = @()
$taskSwooleDatabaseFlags = @()
if ($Profile -in @('mysql', 'all')) {
    $taskDatabaseFlags += @('--with-mysqlnd', '--enable-mysqlnd', '--with-pdo-mysql')
    $taskRequiredExtensions += @('mysqlnd', 'pdo_mysql')
}
if ($Profile -in @('pgsql', 'all')) {
    $taskDatabaseFlags += '--with-pdo-pgsql'
    $taskSwooleDatabaseFlags += '--enable-swoole-pgsql'
    $taskRequiredExtensions += 'pdo_pgsql'
}
if ($Profile -in @('sqlite', 'all')) {
    $taskDatabaseFlags += @('--with-pdo-sqlite', '--with-sqlite3')
    $taskSwooleDatabaseFlags += '--enable-swoole-sqlite'
    $taskRequiredExtensions += @('pdo_sqlite', 'sqlite3')
}
if ($taskRuntime -ne ($DependencyVerification -ne '')) { throw '静态依赖和其真实验证报告必须同时提供。' }
if ($WithPhpx -and !$taskRuntime) { throw 'PHPX 探针必须先启用并验证完整静态扩展。' }
if ($taskRuntime) {
    # 仅作为制备控制器，不加入目标链接输入。先解析应用能力，再决定扩展源码。
    Get-StaticSource 'https://github.com/swoole/typephp/releases/download/v0.9.0/tpc_v0.9.0_windows_x64.zip' '187c2ca1644b37163d5f67725a29752f91da9e058583a8d3e471a71703570ff6' 'host.zip'
    $taskHostPhp = Join-Path $taskWork 'tpc_v0.9.0_windows_x64/php.exe'
    $taskFeatures = & $taskHostPhp -n (Join-Path $PSScriptRoot 'build-profile.php') $Profile
    if ($LASTEXITCODE -ne 0) { throw '应用 profile 闭包解析失败。' }
    $env:TYPEAPP_BUILD_FEATURES = $taskFeatures
    $taskRedisEnabled = (',' + $taskFeatures + ',').Contains(',redis,')
    if ($taskRedisEnabled) { $taskRequiredExtensions += 'redis' }
    $taskDependencyReport = Get-Content -Raw -LiteralPath $DependencyVerification | ConvertFrom-Json
    if (!$taskDependencyReport.passed -or $taskDependencyReport.triplet -ne 'x64-typeapp-static' -or
        $taskDependencyReport.manifest_sha256 -ne (Get-FileHash -LiteralPath (Join-Path $PSScriptRoot 'static-windows/vcpkg.json') -Algorithm SHA256).Hash.ToLowerInvariant() -or
        $taskDependencyReport.triplet_sha256 -ne (Get-FileHash -LiteralPath (Join-Path $PSScriptRoot 'static-windows/x64-typeapp-static.cmake') -Algorithm SHA256).Hash.ToLowerInvariant()) {
        throw '第三方静态依赖尚未通过当前配置的真实验证。'
    }
    $taskLibraries = @()
    foreach ($taskLibrary in $taskDependencyReport.libraries) {
        if ($taskLibrary.file -cnotmatch '^[A-Za-z0-9_+.-]+\.lib$' -or $taskLibrary.sha256 -cnotmatch '^[a-f0-9]{64}$') { throw '归档声明无效。' }
        if (($Profile -ne 'pgsql' -and $Profile -ne 'all') -and $taskLibrary.file -match '(?i)(?:^|[-_])(?:lib)?pq(?:[-_.]|$)|pgcommon|pgport') { continue }
        if (($Profile -ne 'sqlite' -and $Profile -ne 'all') -and $taskLibrary.file -match '(?i)sqlite3') { continue }
        $taskFile = Join-Path $DependenciesDirectory ('lib/' + $taskLibrary.file)
        if ((Get-FileHash -LiteralPath $taskFile -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskLibrary.sha256) { throw '第三方静态依赖字节发生变化。' }
        $taskLibraries += '"' + [IO.Path]::GetFullPath($taskFile) + '"'
    }
    if (!$taskLibraries.Count -or @(Get-ChildItem -LiteralPath $DependenciesDirectory -Filter '*.dll' -File -Recurse).Count) { throw '依赖为空或仍含 DLL。' }
    # 官方 Windows PHP 配置使用自己的库文件名；任务内别名保持原始归档字节。
    $taskDeps = Join-Path $taskWork 'dependencies'
    Copy-Item -LiteralPath $DependenciesDirectory -Destination $taskDeps -Recurse
    foreach ($taskAlias in @(@('pq.lib', 'libpq.lib'), @('zs.lib', 'zlib_a.lib'), @('sqlite3.lib', 'libsqlite3_a.lib'),
        @('iconv.lib', 'libiconv_a.lib'), @('zstd.lib', 'libzstd.lib'))) {
        Copy-Item -LiteralPath (Join-Path $taskDeps ('lib/' + $taskAlias[0])) -Destination (Join-Path $taskDeps ('lib/' + $taskAlias[1]))
    }
    if ($taskRedisEnabled) { Get-StaticSource 'https://pecl.php.net/get/redis-6.3.0.tgz' '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5' 'redis.tar.gz' }
    Get-StaticSource 'https://codeload.github.com/swoole/swoole-src/tar.gz/0f3bee2f0ed8704ce33a336e7feabb0115411dd7' 'b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea' 'swoole.tar.gz'
    if ($taskRedisEnabled) { Move-Item -LiteralPath (Join-Path $taskWork 'redis-6.3.0') -Destination (Join-Path $taskSource 'ext/redis') }
    Move-Item -LiteralPath (Join-Path $taskWork 'swoole-src-0f3bee2f0ed8704ce33a336e7feabb0115411dd7') -Destination (Join-Path $taskSource 'ext/swoole')
    & $taskHostPhp -n (Join-Path $PSScriptRoot 'static-windows/prepare-extensions.php') $taskSource (Join-Path $taskEvidence 'extension-adaptations.json')
    if ($LASTEXITCODE -ne 0) { throw '完整扩展的固定源码适配失败。' }
    $taskRedisFlags = if ($taskRedisEnabled) { @('--enable-redis') } else { @() }
    $taskConfigure += @("--with-php-build=$taskDeps", '--enable-filter', '--enable-tokenizer', '--enable-ctype', '--enable-session',
        '--enable-mbstring', '--disable-mbregex', '--enable-pdo') + $taskDatabaseFlags + @(
        '--enable-sockets', '--with-openssl=yes', '--with-curl', '--enable-zlib', '--with-iconv') + $taskRedisFlags + @(
        '--enable-swoole', '--enable-swoole-thread', '--enable-php-sockets', '--enable-cares') + $taskSwooleDatabaseFlags + @('--enable-swoole-curl')
    [IO.File]::WriteAllText((Join-Path $taskSource 'typeapp-dependencies.rsp'), ($taskLibraries -join "`r`n") +
        "`r`ncrypt32.lib bcrypt.lib ws2_32.lib advapi32.lib user32.lib normaliz.lib iphlpapi.lib secur32.lib wldap32.lib shell32.lib ole32.lib`r`n", $taskUtf8)
    $taskExtraLibraries = '@typeapp-dependencies.rsp'
}

Push-Location $taskSource
$taskOriginalCompilerOptions = $env:_CL_
try {
    if ($taskRuntime) { $env:_CL_ = ($taskOriginalCompilerOptions + ' /std:c++20 /D CURL_STATICLIB /D CARES_STATICLIB /D NGHTTP2_STATICLIB /D LIBICONV_STATIC').Trim() }
    Write-StaticStage 'buildconf: start'
    & .\buildconf.bat 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'buildconf.log')
    if ($LASTEXITCODE -ne 0) { throw 'PHP buildconf 失败。' }
    Write-StaticStage 'configure: start'
    & .\configure.bat @taskConfigure 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'configure.log')
    if ($LASTEXITCODE -ne 0) { throw 'PHP 静态核心配置失败。' }
    # Windows configure 对未知参数只打印警告且返回成功，必须在耗时编译前拒绝。
    $taskConfigured = Get-Content -Raw -LiteralPath (Join-Path $taskEvidence 'configure.log')
    if ($taskConfigured -match 'arguments are invalid, and therefore ignored') {
        throw 'PHP 配置包含被忽略的无效参数，详见 configure.log。'
    }
    if ($taskRuntime) {
        foreach ($taskExtension in $taskRequiredExtensions) {
            $taskExtensionPattern = '(?im)^\s*\|\s*' + [regex]::Escape($taskExtension) + '\s*\|\s*static\s*\|\s*$'
            if (![regex]::IsMatch($taskConfigured, $taskExtensionPattern)) {
                throw ('PHP 配置未静态启用必需扩展：' + $taskExtension)
            }
        }
    }
    Copy-Item -LiteralPath 'Makefile' -Destination (Join-Path $taskEvidence 'Makefile.original')
    # 复用官方已生成的完整对象清单，直接归档；不链接 PHP DLL 或加入其导入库。
    $taskTarget = @'

typeapp-static-core: generated_files $(PHP_GLOBAL_OBJS) $(STATIC_EXT_OBJS) $(EMBED_GLOBAL_OBJS) $(ASM_OBJS)
	$(MAKE_LIB) /nologo /out:$(BUILD_DIR)\typeapp-static.lib $(PHP_GLOBAL_OBJS_RESP) $(STATIC_EXT_OBJS_RESP) $(EMBED_GLOBAL_OBJS_RESP) $(ASM_OBJS)
	$(CC) $(CFLAGS) $(BASE_INCLUDES) /I . /I main /I Zend /I TSRM /I ext /I sapi/embed /D ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 /c typeapp-embed-probe.c /Fo$(BUILD_DIR)\typeapp-embed-probe.obj
	"$(LINK)" /nologo /INCREMENTAL:NO /out:$(BUILD_DIR)\typeapp-embed-probe.exe $(BUILD_DIR)\typeapp-embed-probe.obj $(BUILD_DIR)\typeapp-static.lib $(STATIC_EXT_LIBS) $(LIBS) $(LDFLAGS) $(STATIC_EXT_LDFLAGS) TYPEAPP_EXTRA_LIBRARIES
'@
    $taskTarget = $taskTarget.Replace('TYPEAPP_EXTRA_LIBRARIES', $taskExtraLibraries)
    [IO.File]::AppendAllText((Join-Path $taskSource 'Makefile'), $taskTarget + "`r`n", $taskUtf8)
    Copy-Item -LiteralPath (Join-Path $taskRoot 'plugin/type-build/src/Native/embed-probe.c') -Destination 'typeapp-embed-probe.c'
    Write-StaticStage 'nmake: static core and embed probe'
    & nmake /nologo typeapp-static-core 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'build.log')
    if ($LASTEXITCODE -ne 0) { throw 'PHP 静态核心构建或真实 embed 链接失败。' }
} finally { $env:_CL_ = $taskOriginalCompilerOptions; Pop-Location }
Write-StaticStage 'PE audit: start'

$taskPrograms = @(Get-ChildItem -LiteralPath $taskSource -Filter typeapp-embed-probe.exe -File -Recurse)
if ($taskPrograms.Count -ne 1) { throw '没有生成唯一静态 embed 探针。' }
$taskProgram = $taskPrograms[0].FullName
Copy-Item -LiteralPath $taskProgram -Destination (Join-Path $taskEvidence 'embed-probe.exe')
$taskImports = & dumpbin.exe /nologo /dependents $taskProgram
if ($LASTEXITCODE -ne 0) { throw 'PE 导入审计失败。' }
$taskImports | Set-Content -LiteralPath (Join-Path $taskEvidence 'imports.txt') -Encoding utf8
$taskDlls = @([regex]::Matches(($taskImports -join "`n"), '(?im)^\s+([A-Za-z0-9_.-]+\.dll)\s*$') | ForEach-Object { $_.Groups[1].Value.ToLowerInvariant() })
# Pathcch.lib 与 synchronization.lib 分别导入 Windows 的路径和同步 API-set 契约。
# 完整 Swoole 的 WaitOnAddress/WakeByAddress 使用后者；它不是随应用附带的 DLL。
$taskAllowed = @('kernel32.dll', 'advapi32.dll', 'ws2_32.dll', 'user32.dll', 'shell32.dll', 'ole32.dll', 'oleaut32.dll', 'uuid.dll', 'shlwapi.dll', 'dnsapi.dll', 'iphlpapi.dll', 'bcrypt.dll', 'normaliz.dll', 'crypt32.dll', 'psapi.dll', 'secur32.dll', 'api-ms-win-core-path-l1-1-0.dll', 'api-ms-win-core-synch-l1-2-0.dll')
if (!$taskDlls.Count -or @($taskDlls | Where-Object { $_ -notin $taskAllowed }).Count) { throw '静态核心仍导入非系统 DLL，详见 imports.txt。' }
$taskDeploy = Join-Path $taskWork 'program only'
New-Item -ItemType Directory -Path $taskDeploy | Out-Null
Copy-Item -LiteralPath $taskProgram -Destination (Join-Path $taskDeploy 'probe.exe')
[IO.File]::WriteAllText((Join-Path $taskDeploy 'php.ini'), "display_errors=1`n", $taskUtf8)
New-Item -ItemType Directory -Path (Join-Path $taskDeploy 'empty') | Out-Null
$taskOldPath = $env:PATH
Write-StaticStage 'standalone embed: start'
try {
    $env:PATH = (Join-Path $env:SystemRoot 'System32')
    $taskOutput = & (Join-Path $taskDeploy 'probe.exe') (Join-Path $taskDeploy 'php.ini') (Join-Path $taskDeploy 'empty')
    if ($LASTEXITCODE -ne 0) { throw '静态核心在无 SDK PATH 环境不能启动。' }
} finally { $env:PATH = $taskOldPath }
$taskProfile = ($taskOutput -join "`n") | ConvertFrom-Json
$taskOutput | Set-Content -LiteralPath (Join-Path $taskEvidence 'runtime.json') -Encoding utf8
if ($taskProfile.php -ne '8.5.10' -or !$taskProfile.zts -or $taskProfile.sapi -ne 'embed' -or
    ![string]::Equals([IO.Path]::GetFullPath($taskProfile.'core-library'), (Join-Path $taskDeploy 'probe.exe'), [StringComparison]::OrdinalIgnoreCase)) {
    throw '实际 PHP 核心未位于静态主程序内。'
}
if ($taskRuntime) {
    foreach ($taskExtension in $taskRequiredExtensions) {
        if ($null -eq $taskProfile.extensions.$taskExtension) { throw ('静态核心缺少必需扩展：' + $taskExtension) }
    }
}
@{ passed=$true; profile=$Profile; features=($taskFeatures -split ','); scope=$(if ($taskRuntime) { 'PHP and extensions embed only; no PHPX or application acceptance' } else { 'PHP core embed only; no application or Swoole acceptance' }); php=$taskProfile.php; zts=$taskProfile.zts;
    artifact_sha256=(Get-FileHash -LiteralPath $taskProgram -Algorithm SHA256).Hash.ToLowerInvariant(); system_libraries=$taskDlls;
    extensions=$taskProfile.extensions } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $taskEvidence 'verification.json') -Encoding utf8
Write-Host 'Windows 静态 embed 探针通过；范围以 verification.json 为准，尚不代表 PHPX 或应用验收。'
if ($WithPhpx) {
    Write-StaticStage 'PHPX static: start'
    Get-StaticSource 'https://codeload.github.com/swoole/phpx/tar.gz/0dfa613d2057dcd4aa319ec9b6816f68df2403e4' '591a8d2116568f42ba969f58a0c72a26d47fccca4d0debdf5f7bd0a2480df4b3' 'phpx.tar.gz'
    $taskPhpx = Join-Path $taskWork 'phpx-0dfa613d2057dcd4aa319ec9b6816f68df2403e4'
    & $taskHostPhp -n -r 'require $argv[1]."/plugin/type-build/src/PhpxThreadSource.php";echo json_encode((new Type\Build\PhpxThreadSource())->apply($argv[2]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);' $taskRoot $taskPhpx |
        Set-Content -LiteralPath (Join-Path $taskEvidence 'phpx-adaptations.json') -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'PHPX 固定源码适配失败。' }
    & (Join-Path $PSScriptRoot 'static-windows/build-phpx.ps1') -PhpSource $taskSource -PhpxSource $taskPhpx `
        -PhpArchive (Join-Path (Split-Path $taskProgram -Parent) 'typeapp-static.lib') `
        -DependenciesDirectory $DependenciesDirectory -HostPhp $taskHostPhp -Directory (Join-Path $taskWork 'phpx-static') -Profile $Profile
    & $taskHostPhp -n (Join-Path $PSScriptRoot 'static-windows/export-sdk.php') $taskWork $DependenciesDirectory $DependencyVerification $Profile
    if ($LASTEXITCODE -ne 0) { throw 'Windows 静态 SDK 导出失败。' }
}
