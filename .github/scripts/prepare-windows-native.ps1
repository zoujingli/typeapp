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

$archive = Join-Path $Directory 'typephp.zip'
Invoke-WebRequest -Uri 'https://github.com/swoole/typephp/releases/download/v0.9.0/tpc_v0.9.0_windows_x64.zip' -OutFile $archive
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
Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/pecl/releases/redis/6.3.0/php_redis-6.3.0-8.5-ts-vs17-x64.zip' -OutFile $redisArchive
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $redisArchive).Hash.ToLowerInvariant() -ne '1a1e9c721dd64939dbeac1c855eb5bb93a45e5a825ad0e13b42f46731c8223d2') { throw 'Redis extension checksum mismatch.' }
$redis = Join-Path $Directory 'redis'
Expand-Archive -LiteralPath $redisArchive -DestinationPath $redis
Copy-Item -LiteralPath (Join-Path $redis 'php_redis.dll') -Destination (Join-Path $sdk 'ext\php_redis.dll')

$configuration = @(
    ('extension_dir="' + $sdk + '\ext"')
    'memory_limit=1024M'
    'display_errors=1'
    'swoole.enable_library=Off'
    'date.timezone=UTC'
)
foreach ($extension in @('mbstring', 'openssl', 'curl', 'zip', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis')) {
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
$settings = @{ PHP_HOME=$sdk; PHPX_HOME=(Join-Path $sdk 'phpx'); PHPRC=$ini; PHP_INI_SCAN_DIR=$empty; COMPOSER_HOME=(Join-Path $Directory 'composer-home'); COMPOSER_NO_INTERACTION='1' }
foreach ($key in $settings.Keys) {
    [Environment]::SetEnvironmentVariable($key, $settings[$key], 'Process')
    Add-Content -LiteralPath $env:GITHUB_ENV -Value ($key + '=' + $settings[$key]) -Encoding utf8
}
Add-Content -LiteralPath $env:GITHUB_PATH -Value $sdk -Encoding utf8
Add-Content -LiteralPath $env:GITHUB_PATH -Value $phpxBuild -Encoding utf8
& (Join-Path $sdk 'php.exe') -r 'if(PHP_VERSION!=="8.5.10" || !PHP_ZTS || PHP_INT_SIZE!==8){exit(1);} foreach(["dom","mbstring","pdo_mysql","pdo_pgsql","pdo_sqlite","redis"] as $e){if(!extension_loaded($e)){fwrite(STDERR,"missing ".$e);exit(1);}} if(phpversion("redis")!=="6.3.0"){exit(1);} echo PHP_VERSION," ZTS x64 SDK verified\n";'
if ($LASTEXITCODE -ne 0) { throw 'The real Windows PHP runtime did not match the SDK contract.' }
