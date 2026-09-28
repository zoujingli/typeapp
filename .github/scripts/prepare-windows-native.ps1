param([string]$Directory = (Join-Path $env:RUNNER_TEMP 'typephp-native-sdk'))
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT' -or !$env:RUNNER_TEMP) { throw 'This preparation requires a Windows runner.' }
if (Test-Path -LiteralPath $Directory) { throw 'SDK destination already exists; do not overwrite another installation.' }
$parent = [IO.Path]::GetFullPath($env:RUNNER_TEMP).TrimEnd('\') + '\'
if (![IO.Path]::GetFullPath($Directory).StartsWith($parent, [StringComparison]::OrdinalIgnoreCase)) { throw 'SDK must remain in this runner temporary directory.' }
New-Item -ItemType Directory -Path $Directory | Out-Null

$vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
$visualStudio = & $vswhere -latest -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if ($LASTEXITCODE -ne 0 -or !$visualStudio) { throw 'MSVC x64 installation was not found.' }
$setup = 'call "' + $visualStudio + '\Common7\Tools\VsDevCmd.bat" -arch=x64 -host_arch=x64 >nul && set'
$variables = & $env:ComSpec /d /s /c $setup
if ($LASTEXITCODE -ne 0) { throw 'MSVC environment setup failed.' }
# Only compiler-related values are exported; do not print or copy the complete runner environment.
$allowed = @('PATH', 'VCToolsInstallDir', 'WindowsSdkDir', 'WindowsSDKVersion', 'INCLUDE', 'LIB', 'LIBPATH')
foreach ($line in $variables) {
    if ($line -match '^([^=]+)=(.*)$' -and $matches[1] -in $allowed) {
        $name = $matches[1]
        if ($name -ieq 'PATH') { $name = 'PATH' }
        [Environment]::SetEnvironmentVariable($name, $matches[2], 'Process')
        Add-Content -LiteralPath $env:GITHUB_ENV -Value ($name + '=' + $matches[2]) -Encoding utf8
    }
}

function Get-VerifiedDownload {
    param([string]$Url, [string]$Path)
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (!(Test-Path -LiteralPath $curl)) { throw 'Windows 系统 curl 不存在。' }
    $lastError = $null
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        & $curl -fsSL --retry 3 --retry-all-errors --connect-timeout 20 --max-time 180 -A 'typeapp-sdk-fetch' -o $Path $Url
        if ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath $Path) -and (Get-Item -LiteralPath $Path).Length -gt 0) { return }
        $lastError = "下载失败（$LASTEXITCODE）：$Url"
        if (Test-Path -LiteralPath $Path) { Remove-Item -LiteralPath $Path -Force }
        if ($attempt -lt 3) { Start-Sleep -Seconds (5 * $attempt) }
    }
    throw $lastError
}

$archive = Join-Path $Directory 'typephp.zip'
# 仅复用该发行包的 PHP 8.5.10 ZTS SDK；编译器来自 Composer，PHPX 在下方按当前锁定源码重建。
Get-VerifiedDownload 'https://github.com/swoole/typephp/releases/download/v0.9.0/tpc_v0.9.0_windows_x64.zip' $archive
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant() -ne '187c2ca1644b37163d5f67725a29752f91da9e058583a8d3e471a71703570ff6') { throw 'TypePHP SDK checksum mismatch.' }
Expand-Archive -LiteralPath $archive -DestinationPath $Directory
$sdk = Join-Path $Directory 'tpc_v0.9.0_windows_x64'
# 发行包把 DLL 放在顶层，锁定编译器要求 PHPX_HOME/build；只保留一个运行库位置。
$phpxBuild = Join-Path $sdk 'phpx\build'
$packagedPhpx = Join-Path $sdk 'phpx.dll'
if (!(Test-Path -LiteralPath $packagedPhpx) -or !(Test-Path -LiteralPath (Join-Path $sdk 'phpx\lib\phpx.lib'))) { throw 'The verified SDK is missing PHPX runtime or import library.' }
$phpxDigest = (Get-FileHash -Algorithm SHA256 -LiteralPath $packagedPhpx).Hash
New-Item -ItemType Directory -Force -Path $phpxBuild | Out-Null
$phpxRuntime = Join-Path $phpxBuild 'phpx.dll'
Move-Item -LiteralPath $packagedPhpx -Destination $phpxRuntime
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $phpxRuntime).Hash -ne $phpxDigest) { throw 'PHPX runtime bytes changed while preparing the compiler layout.' }
$redisArchive = Join-Path $Directory 'redis.zip'
Get-VerifiedDownload 'https://downloads.php.net/~windows/pecl/releases/redis/6.3.0/php_redis-6.3.0-8.5-ts-vs17-x64.zip' $redisArchive
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $redisArchive).Hash.ToLowerInvariant() -ne '1a1e9c721dd64939dbeac1c855eb5bb93a45e5a825ad0e13b42f46731c8223d2') { throw 'Redis extension checksum mismatch.' }
$redis = Join-Path $Directory 'redis'
Expand-Archive -LiteralPath $redisArchive -DestinationPath $redis
Copy-Item -LiteralPath (Join-Path $redis 'php_redis.dll') -Destination (Join-Path $sdk 'ext\php_redis.dll')

# 默认复用 type-build 组件的固定模块；维护者显式重建时使用官方 Windows/phpize 入口。
# PHP SDK、PHPX 与其他原生依赖仍独立准备。
$taskRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$taskTar = Join-Path $env:SystemRoot 'System32/tar.exe'
if (!(Test-Path -LiteralPath $taskTar)) { throw 'Windows 系统 tar 不存在。' }
$taskEvidence = Join-Path $taskRoot ('build/windows-runtime-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $taskEvidence | Out-Null
function Get-VerifiedArchive {
    param([string]$Url, [string]$Digest, [string]$Path)
    Get-VerifiedDownload $Url $Path
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant() -ne $Digest) { throw ('构建依赖摘要不符：' + [IO.Path]::GetFileName($Path)) }
}
$taskToolsReference = '1142e4abaf90ceb6cc25d983797b25cc948669cf'
$taskToolsDigest = '083324ab6ad0f5b8727539d717600ab0f29a2fe84bd147095cf0b115a63a45c4'
if ($env:TYPE_SWOOLE_BUILD_FROM_SOURCE -eq '1') {
    $taskToolsArchive = Join-Path $Directory 'php-sdk-tools.zip'
    Get-VerifiedArchive ('https://codeload.github.com/php/php-sdk-binary-tools/zip/' + $taskToolsReference) $taskToolsDigest $taskToolsArchive
    Expand-Archive -LiteralPath $taskToolsArchive -DestinationPath $Directory
    $taskTools = Join-Path $Directory ('php-sdk-binary-tools-' + $taskToolsReference)
    # phpize 的 configure 需要 PHP 官方 SDK 工具，即使不重新生成 PHP 解析器也会检查 bison/re2c。
    $env:PATH = (Join-Path $taskTools 'bin') + ';' + (Join-Path $taskTools 'msys2/usr/bin') + ';' + $env:PATH
    foreach ($taskTool in @('bison', 're2c')) {
        & (Join-Path $taskTools ('msys2/usr/bin/' + $taskTool + '.exe')) --version | Out-Null
        if ($LASTEXITCODE -ne 0) { throw ('PHP SDK 构建工具不能运行：' + $taskTool) }
    }
    $taskDevelArchive = Join-Path $Directory 'php-devel.zip'
    Get-VerifiedArchive 'https://downloads.php.net/~windows/releases/archives/php-devel-pack-8.5.10-Win32-vs17-x64.zip' '0031d279f13f21e81fd62f9a98e919f28b1875ba457916d60daed85586e479dd' $taskDevelArchive
    Expand-Archive -LiteralPath $taskDevelArchive -DestinationPath (Join-Path $Directory 'php-devel')
    $taskDevel = Join-Path $Directory 'php-devel/php-8.5.10-devel-vs17-x64'
}
$taskDependencies = @{
    'openssl-3.5.7-vs17-x64.zip' = 'bc86233e1f0826b0e0c8b745ed5221a781a1d403c6f69bb8fbe086e07dd3979e'
    'zlib-1.3.2-vs17-x64.zip' = '3038dbd503d494718898a150f93d79627df442c344d1d6e77ec413cdeec7999e'
    'brotli-1.2.0-vs17-x64.zip' = '61aec2187d4317826b374f4f2d8ef4b652edb46ae9d5b68820a840c94427644e'
    'libzstd-1.5.7-vs17-x64.zip' = '59dbce5548104788ccfc5040bb7140916454fd0138d306705bd9b3d67741dc4a'
    'nghttp2-1.70.0-vs17-x64.zip' = '51e21698b80f4e1a151508656f6ca7caf13adfef65dfefbc160645cf9c1129f1'
    'libpq-16.15-vs17-x64.zip' = 'f5fa47ebb2cf650428870e24ab2b8a7ce9663a782ddb9d790dfb432fd22e4728'
    'sqlite3-3.53.4-vs17-x64.zip' = 'abb5e36fc76803b4df9a63ab56b931f37058d62c9e6303172015ff4f101ef4cd'
}
$taskDeps = Join-Path $Directory 'deps'
foreach ($taskDependency in $taskDependencies.Keys) {
    $taskZip = Join-Path $Directory $taskDependency
    Get-VerifiedArchive ('https://downloads.php.net/~windows/php-sdk/deps/vs17/x64/' + $taskDependency) $taskDependencies[$taskDependency] $taskZip
    Expand-Archive -LiteralPath $taskZip -DestinationPath $taskDeps -Force
}
$taskSwooleReference = '0f3bee2f0ed8704ce33a336e7feabb0115411dd7'
if ($env:TYPE_SWOOLE_BUILD_FROM_SOURCE -eq '1') {
    $taskSwooleArchive = Join-Path $Directory 'swoole.tar.gz'
    Get-VerifiedArchive ('https://codeload.github.com/swoole/swoole-src/tar.gz/' + $taskSwooleReference) 'b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea' $taskSwooleArchive
    & $taskTar -xzf $taskSwooleArchive -C $Directory
    if ($LASTEXITCODE -ne 0) { throw 'Swoole 源码解包失败。' }
    $taskSwoole = Join-Path $Directory ('swoole-src-' + $taskSwooleReference)
    $taskPatch = 'foreach(["SwooleThreadSource","SwooleHttpSource","SwooleSocketSource"] as $name){require $argv[1]."/plugin/type-build/src/".$name.".php";$class="Type\\Build\\".$name;$patch=new $class();$report[$name]=$patch->apply($argv[2]);} $report["tls"]=(new Type\Build\SwooleSocketSource())->applyTls($argv[2]);echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);'
    & (Join-Path $sdk 'php.exe') -n -r $taskPatch $taskRoot $taskSwoole | Set-Content -LiteralPath (Join-Path $taskEvidence 'swoole-source.json') -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw '固定 Swoole 源码适配核验失败。' }
    # 与静态 SDK 共用固定的官方构建与 IOCP 适配，避免两套实现漂移。
    & (Join-Path $sdk 'php.exe') -n -r 'require $argv[1]."/plugin/type-build/src/SwooleWindowsSource.php";echo json_encode((new Type\Build\SwooleWindowsSource())->apply($argv[2]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);' $taskRoot $taskSwoole | Set-Content -LiteralPath (Join-Path $taskEvidence 'windows-source.json') -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'Swoole Windows 固定源码适配失败。' }
    Push-Location $taskSwoole
    try {
        & (Join-Path $taskDevel 'phpize.bat') 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'phpize.log')
        if ($LASTEXITCODE -ne 0) { throw 'Swoole phpize 失败。' }
        & .\configure.bat '--enable-swoole=shared' '--enable-swoole-thread' '--enable-mysqlnd' '--enable-php-sockets' '--enable-swoole-pgsql' '--enable-swoole-sqlite' "--with-php-build=$taskDeps" '--with-mp=2' 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'configure.log')
        if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath 'Makefile')) { throw 'Swoole Windows 配置失败。' }
        $taskFeatures = [IO.File]::ReadAllText((Join-Path $taskDevel 'include/main/config.pickle.h'))
        foreach ($taskFeature in @('SW_USE_MYSQLND', 'SW_USE_PGSQL', 'SW_USE_SQLITE')) {
            if ($taskFeatures -notmatch ('(?m)^#define\s+' + $taskFeature + '\s+1\b')) { throw ('Swoole 缺少必需 PDO hook：' + $taskFeature) }
        }
        # PHP 8.5 的官方 Swoole 关闭回调使用指定初始化；MSVC 需要显式 C++20。
        $taskCompilerOptions = $env:_CL_
        try {
            $env:_CL_ = ($taskCompilerOptions + ' /std:c++20').Trim()
            & nmake /nologo 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'swoole-build.log')
            if ($LASTEXITCODE -ne 0) { throw 'Swoole Windows 编译失败。' }
        } finally { $env:_CL_ = $taskCompilerOptions }
    } finally { Pop-Location }
    $taskModules = @(Get-ChildItem -LiteralPath $taskSwoole -Filter php_swoole.dll -File -Recurse)
    if ($taskModules.Count -ne 1) { throw 'Swoole 构建没有产生唯一扩展。' }
    Copy-Item -LiteralPath $taskModules[0].FullName -Destination (Join-Path $sdk 'ext/php_swoole.dll')
    $taskSwooleSymbols = [IO.Path]::ChangeExtension($taskModules[0].FullName, '.pdb')
    if (Test-Path -LiteralPath $taskSwooleSymbols) {
        Copy-Item -LiteralPath $taskSwooleSymbols -Destination (Join-Path $sdk 'ext/php_swoole.pdb')
    }
} else {
    $taskBundledSwoole = & (Join-Path $sdk 'php.exe') -n (Join-Path $taskRoot 'tools/select-swoole-module.php')
    if ($LASTEXITCODE -ne 0 -or !$taskBundledSwoole -or !(Test-Path -LiteralPath $taskBundledSwoole -PathType Leaf)) { throw '项目内置 Swoole 校验失败。' }
    Copy-Item -LiteralPath $taskBundledSwoole -Destination (Join-Path $sdk 'ext/php_swoole.dll')
    Copy-Item -LiteralPath (Join-Path $taskRoot 'plugin/type-build/resources/swoole/manifest.json') -Destination (Join-Path $taskEvidence 'swoole-bundle.json')
    $taskToolsReference = $null
    $taskToolsDigest = $null
    Write-Host '已复用项目内置 Swoole，无需下载或编译 Swoole 源码。'
}
# 将实际链接的依赖与 SDK 放在同一搜索目录，运行清单随后从真实加载模块核验。
Get-ChildItem -LiteralPath (Join-Path $taskDeps 'bin') -Filter '*.dll' -File | Copy-Item -Destination $sdk -Force

$taskPhpxArchive = Join-Path $Directory 'phpx.tar.gz'
Get-VerifiedArchive 'https://codeload.github.com/swoole/phpx/tar.gz/0dfa613d2057dcd4aa319ec9b6816f68df2403e4' '591a8d2116568f42ba969f58a0c72a26d47fccca4d0debdf5f7bd0a2480df4b3' $taskPhpxArchive
& $taskTar -xzf $taskPhpxArchive -C $Directory
if ($LASTEXITCODE -ne 0) { throw 'PHPX 源码解包失败。' }
$taskPhpx = Join-Path $Directory 'phpx-0dfa613d2057dcd4aa319ec9b6816f68df2403e4'
& (Join-Path $sdk 'php.exe') -n -r 'require $argv[1]."/plugin/type-build/src/PhpxThreadSource.php";echo json_encode((new Type\Build\PhpxThreadSource())->apply($argv[2]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);' $taskRoot $taskPhpx | Set-Content -LiteralPath (Join-Path $taskEvidence 'phpx-source.json') -Encoding utf8
if ($LASTEXITCODE -ne 0) { throw 'PHPX 固定源码适配失败。' }
$env:PHP_HOME = $sdk
& cmake -S $taskPhpx -B (Join-Path $taskPhpx 'build') -G 'Visual Studio 17 2022' -A x64 -DBUILD_TESTS=OFF -DBUILD_EXT=OFF 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'phpx-configure.log')
if ($LASTEXITCODE -ne 0) { throw 'PHPX Windows 配置失败。' }
& cmake --build (Join-Path $taskPhpx 'build') --config Release --target phpx --parallel 2 2>&1 | Tee-Object -FilePath (Join-Path $taskEvidence 'phpx-build.log')
if ($LASTEXITCODE -ne 0) { throw 'PHPX Windows 编译失败。' }
$phpxBuild = Join-Path $taskPhpx 'build'
$taskPhpxImports = @(Get-ChildItem -LiteralPath $taskPhpx -Filter phpx.lib -File -Recurse)
if ($taskPhpxImports.Count -ne 1 -or !(Test-Path -LiteralPath (Join-Path $phpxBuild 'phpx.dll'))) { throw 'PHPX 构建产物不完整。' }
New-Item -ItemType Directory -Force -Path (Join-Path $taskPhpx 'lib') | Out-Null
$taskPhpxImport = Join-Path $taskPhpx 'lib/phpx.lib'
if ([IO.Path]::GetFullPath($taskPhpxImports[0].FullName) -ne [IO.Path]::GetFullPath($taskPhpxImport)) {
    Copy-Item -LiteralPath $taskPhpxImports[0].FullName -Destination $taskPhpxImport
}

$configuration = @(
    ('extension_dir="' + $sdk + '\ext"')
    'memory_limit=1024M'
    'display_errors=1'
    'swoole.enable_library=On'
    'date.timezone=UTC'
)
foreach ($extension in @('mbstring', 'openssl', 'curl', 'zip', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'sockets', 'swoole')) {
    if (!(Test-Path -LiteralPath (Join-Path $sdk ('ext\php_' + $extension + '.dll')))) { throw ('SDK is missing ' + $extension) }
    $configuration += 'extension=php_' + $extension + '.dll'
}
$ini = Join-Path $sdk 'php.ini'
[IO.File]::WriteAllText($ini, ($configuration -join "`n") + "`n", [Text.UTF8Encoding]::new($false))
$empty = Join-Path $Directory 'empty-ini'
New-Item -ItemType Directory -Path $empty | Out-Null
$env:PHPRC = $ini
$env:PHP_INI_SCAN_DIR = $empty
$env:PATH = $sdk + ';' + $phpxBuild + ';' + $env:PATH
$settings = @{ PHP_HOME=$sdk; PHPX_HOME=$taskPhpx; PHPRC=$ini; PHP_INI_SCAN_DIR=$empty; COMPOSER_HOME=(Join-Path $Directory 'composer-home'); COMPOSER_NO_INTERACTION='1' }
foreach ($key in $settings.Keys) {
    [Environment]::SetEnvironmentVariable($key, $settings[$key], 'Process')
    Add-Content -LiteralPath $env:GITHUB_ENV -Value ($key + '=' + $settings[$key]) -Encoding utf8
}
Add-Content -LiteralPath $env:GITHUB_PATH -Value $sdk -Encoding utf8
Add-Content -LiteralPath $env:GITHUB_PATH -Value $phpxBuild -Encoding utf8
& (Join-Path $sdk 'php.exe') -r 'if(PHP_VERSION!=="8.5.10" || !PHP_ZTS || PHP_INT_SIZE!==8){exit(1);} foreach(["dom","mbstring","pdo_mysql","pdo_pgsql","pdo_sqlite","redis","swoole"] as $e){if(!extension_loaded($e)){fwrite(STDERR,"missing ".$e);exit(1);}} if(phpversion("redis")!=="6.3.0" || version_compare(phpversion("swoole"),"6.2","<") || version_compare(phpversion("swoole"),"7",">=") || !filter_var(ini_get("swoole.enable_library"),FILTER_VALIDATE_BOOL)){exit(1);} echo PHP_VERSION," ZTS x64 SDK verified; Swoole ",phpversion("swoole"),"\n";'
if ($LASTEXITCODE -ne 0) { throw 'The real Windows PHP runtime did not match the SDK contract.' }
& (Join-Path $sdk 'php.exe') -r 'if(!method_exists(Swoole\Thread::class,"startNative") || Swoole\Thread::NATIVE_ENTRY_ABI!==2 || !defined("SWOOLE_HOOK_PDO_PGSQL") || !defined("SWOOLE_HOOK_PDO_SQLITE")){exit(1);}'
if ($LASTEXITCODE -ne 0) { throw 'Swoole 原生线程 ABI 或 PDO hook 不完整。' }
@{ platform='Windows'; architecture='x64'; php='8.5.10'; sdk_tools_source=$taskToolsReference; sdk_tools_sha256=$taskToolsDigest; swoole_source=$taskSwooleReference; swoole_sha256=(Get-FileHash -LiteralPath (Join-Path $sdk 'ext/php_swoole.dll') -Algorithm SHA256).Hash.ToLowerInvariant(); phpx_source='0dfa613d2057dcd4aa319ec9b6816f68df2403e4'; phpx_sha256=(Get-FileHash -LiteralPath (Join-Path $phpxBuild 'phpx.dll') -Algorithm SHA256).Hash.ToLowerInvariant(); dependencies=$taskDependencies; passed=$true } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $taskEvidence 'verification.json') -Encoding utf8
