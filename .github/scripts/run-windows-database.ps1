param([Parameter(Mandatory)][ValidateSet('mysql', 'pgsql')][string]$Driver, [switch]$OrmOnly)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# 不接管镜像预装服务，不使用Docker/WSL；只在可丢弃的原生runner工作。
if (!$IsWindows -or $env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows') {
    throw '此入口只接受GitHub Windows原生runner。'
}
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ($taskRoot -ne [IO.Path]::GetFullPath($env:GITHUB_WORKSPACE) -or !$env:RUNNER_TEMP -or !$env:PHP_HOME) {
    throw '需要明确的工作区、临时目录及锁定PHP SDK。'
}
Set-Location -LiteralPath $taskRoot
$taskIdentity = [Guid]::NewGuid().ToString('N')
$taskWork = Join-Path $env:RUNNER_TEMP ('type-native-db-' + $taskIdentity)
if (Test-Path -LiteralPath $taskWork) { throw '不能覆盖既有数据库工作目录。' }
[IO.Directory]::CreateDirectory($taskWork) | Out-Null
$taskSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$taskAcl = Get-Acl -LiteralPath $taskWork
$taskAcl.SetAccessRuleProtection($true, $false)
$taskAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($taskSid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
Set-Acl -LiteralPath $taskWork -AclObject $taskAcl
$taskEvidence = Join-Path $taskRoot ('build/windows-database-' + $Driver + '-' + $taskIdentity)
[IO.Directory]::CreateDirectory($taskEvidence) | Out-Null
$taskPassword = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)).ToLowerInvariant()
Write-Output ('::add-mask::' + $taskPassword)
$script:taskSecrets = @($taskPassword)

function Start-TaskProcess {
    param([string]$File, [string[]]$CommandArguments, [hashtable]$Environment = @{}, [switch]$InheritOutput)
    $taskInfo = [Diagnostics.ProcessStartInfo]::new()
    $taskInfo.FileName = $File
    $taskInfo.WorkingDirectory = $taskRoot
    $taskInfo.UseShellExecute = $false
    $taskInfo.CreateNoWindow = $true
    $taskInfo.RedirectStandardOutput = !$InheritOutput
    $taskInfo.RedirectStandardError = !$InheritOutput
    foreach ($taskArgument in $CommandArguments) { $taskInfo.ArgumentList.Add($taskArgument) }
    foreach ($taskKey in $Environment.Keys) { $taskInfo.Environment[$taskKey] = [string]$Environment[$taskKey] }
    $taskProcess = [Diagnostics.Process]::Start($taskInfo)
    $taskOutput = $null
    $taskError = $null
    if (!$InheritOutput) {
        $taskOutput = $taskProcess.StandardOutput.ReadToEndAsync()
        $taskError = $taskProcess.StandardError.ReadToEndAsync()
    }
    return @{ Process=$taskProcess; Output=$taskOutput; Error=$taskError }
}

function Complete-TaskProcess {
    param([hashtable]$Handle, [int]$Seconds, [string]$Log)
    $taskTimedOut = !$Handle.Process.WaitForExit($Seconds * 1000)
    if ($taskTimedOut) {
        $Handle.Process.Kill($true)
        if (!$Handle.Process.WaitForExit(10000)) { throw '本轮子进程未确认退出。' }
    }
    if (($null -ne $Handle.Output -and !$Handle.Output.Wait(10000)) -or ($null -ne $Handle.Error -and !$Handle.Error.Wait(10000))) { throw '子进程已退出但输出管道未关闭，不能记作通过。' }
    $taskOutput = if ($null -ne $Handle.Output) { $Handle.Output.GetAwaiter().GetResult() } else { '' }
    $taskErrorOutput = if ($null -ne $Handle.Error) { $Handle.Error.GetAwaiter().GetResult() } else { '' }
    $taskText = $taskOutput + $taskErrorOutput
    foreach ($taskSecret in $script:taskSecrets) { $taskText = $taskText.Replace($taskSecret, '<REDACTED>') }
    $taskText += "`n[process] exit=" + $Handle.Process.ExitCode + '; timeout=' + $taskTimedOut + "`n"
    [IO.File]::WriteAllText($Log, $taskText, [Text.UTF8Encoding]::new($false))
    if ($taskTimedOut -or $Handle.Process.ExitCode -ne 0) { throw ('原生命令失败，详见：' + $Log) }
    return $taskOutput
}

function Invoke-TaskProcess {
    param([string]$File, [string[]]$CommandArguments, [string]$Log, [int]$Seconds = 120, [hashtable]$Environment = @{}, [switch]$InheritOutput)
    $taskHandle = Start-TaskProcess $File $CommandArguments $Environment -InheritOutput:$InheritOutput
    try { return Complete-TaskProcess $taskHandle $Seconds $Log } finally { $taskHandle.Process.Dispose() }
}

function Get-TaskPort {
    $taskListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    try { $taskListener.Start(); return $taskListener.LocalEndpoint.Port } finally { $taskListener.Stop() }
}

$taskSources = Get-Content -LiteralPath (Join-Path $taskRoot '.github/windows-databases.json') -Raw | ConvertFrom-Json -AsHashtable
$taskSource = $taskSources[$Driver]
if ($taskSource.sha256 -notmatch '^[a-f0-9]{64}$' -or $taskSource.folder -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*$') { throw '数据库来源声明无效。' }
$taskUri = [Uri]::new($taskSource.url)
if ($taskUri.Scheme -ne 'https' -or $taskUri.Host -notin @('cdn.mysql.com', 'sbp.enterprisedb.com')) { throw '数据库下载必须来自已声明的官方HTTPS来源。' }
$taskArchive = Join-Path $taskWork 'database.zip'
$taskExtract = Join-Path $taskWork 'tools'
$taskServer = $null
$taskPgStarted = $false
$taskData = Join-Path $taskWork 'data'
$taskSecretFile = Join-Path $taskWork 'initial-secret.txt'
$taskPhp = Join-Path $env:PHP_HOME 'php.exe'
$taskPassed = $false
$taskCleanupPassed = $true
$taskPort = Get-TaskPort
$taskEnvironment = @{}
try {
    Invoke-WebRequest -Uri $taskSource.url -OutFile $taskArchive
    if ((Get-FileHash -LiteralPath $taskArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskSource.sha256) { throw '数据库便携包摘要不符。' }
    # 受信摘要之外仍检查解包路径；不把绝对路径或跳转条目写到临时根之外。
    $taskZip = [IO.Compression.ZipFile]::OpenRead($taskArchive)
    try {
        foreach ($taskEntry in $taskZip.Entries) {
            $taskEntryPath = $taskEntry.FullName.Replace('\', '/')
            if ($taskEntryPath.StartsWith('/') -or $taskEntryPath.Contains(':') -or $taskEntryPath -match '(^|/)\.\.(/|$)') { throw '归档包含越界条目。' }
        }
    } finally { $taskZip.Dispose() }
    Expand-Archive -LiteralPath $taskArchive -DestinationPath $taskExtract
    Remove-Item -LiteralPath $taskArchive
    $taskBin = Join-Path (Join-Path $taskExtract $taskSource.folder) 'bin'
    $taskVersionTool = Join-Path $taskBin $(if ($Driver -eq 'mysql') { 'mysqld.exe' } else { 'pg_ctl.exe' })
    $taskVersion = Invoke-TaskProcess $taskVersionTool @('--version') (Join-Path $taskEvidence 'version.log')
    if ($taskVersion -notmatch ([regex]::Escape($taskSource.version) + '(\s|$)')) { throw '实际数据库版本与固定版本不符。' }
    if ($Driver -eq 'mysql') {
        $taskMysqlRoot = Split-Path $taskBin -Parent
        Invoke-TaskProcess $taskVersionTool @('--no-defaults', '--initialize-insecure', "--basedir=$taskMysqlRoot", "--datadir=$taskData") (Join-Path $taskEvidence 'initialize.log') | Out-Null
        $taskSql = "ALTER USER 'root'@'localhost' IDENTIFIED BY '$taskPassword';`nCREATE DATABASE type_app_test;`n"
        [IO.File]::WriteAllText($taskSecretFile, $taskSql, [Text.UTF8Encoding]::new($false))
        $taskServer = Start-TaskProcess $taskVersionTool @('--no-defaults', "--basedir=$taskMysqlRoot", "--datadir=$taskData", '--bind-address=127.0.0.1', "--port=$taskPort", '--mysqlx=OFF', "--init-file=$taskSecretFile", '--console')
        $taskUser = 'root'
    } else {
        [IO.File]::WriteAllText($taskSecretFile, $taskPassword + "`n", [Text.UTF8Encoding]::new($false))
        Invoke-TaskProcess (Join-Path $taskBin 'initdb.exe') @('-D', $taskData, '-U', 'type_app', '--auth=scram-sha-256', '--encoding=UTF8', '--locale=C', "--pwfile=$taskSecretFile") (Join-Path $taskEvidence 'initialize.log') | Out-Null
        Remove-Item -LiteralPath $taskSecretFile
        $taskPgStarted = $true
        # pg_ctl 会把标准句柄传给常驻的 CMD/postgres；启动输出沿用 CI 控制台，不能等待专用管道 EOF。
        Invoke-TaskProcess $taskVersionTool @('-D', $taskData, '-l', (Join-Path $taskWork 'postgres.log'), '-w', '-t', '60', '-o', "-h 127.0.0.1 -p $taskPort", 'start') (Join-Path $taskEvidence 'start.log') -InheritOutput | Out-Null
        Invoke-TaskProcess (Join-Path $taskBin 'createdb.exe') @('-h', '127.0.0.1', '-p', [string]$taskPort, '-U', 'type_app', '--no-password', 'type_app_test') (Join-Path $taskEvidence 'create-database.log') 60 @{ PGPASSWORD=$taskPassword } | Out-Null
        $taskUser = 'type_app'
    }
    $taskPrefix = 'TYPE_' + $Driver.ToUpperInvariant() + '_'
    $taskEnvironment = @{ ($taskPrefix+'HOST')='127.0.0.1'; ($taskPrefix+'PORT')=[string]$taskPort; ($taskPrefix+'DATABASE')='type_app_test'; ($taskPrefix+'USER')=$taskUser; ($taskPrefix+'PASSWORD')=$taskPassword; TYPE_DB_PROBE_DRIVER=$Driver; TYPE_COMPOSER_PHAR=(Join-Path $env:PHP_HOME 'composer.phar') }
    $taskProbe = '$d=getenv("TYPE_DB_PROBE_DRIVER");$p="TYPE_".strtoupper($d)."_";$dsn=($d==="mysql"?"mysql:":"pgsql:")."host=".getenv($p."HOST").";port=".getenv($p."PORT").";dbname=".getenv($p."DATABASE");$until=microtime(true)+60;do{try{$c=new PDO($dsn,getenv($p."USER"),getenv($p."PASSWORD"));if((int)$c->query("SELECT 1")->fetchColumn()===1){exit(0);}}catch(Throwable){}usleep(100000);}while(microtime(true)<$until);exit(1);'
    Invoke-TaskProcess $taskPhp @('-r', $taskProbe) (Join-Path $taskEvidence 'ready.log') 90 $taskEnvironment | Out-Null
    if (Test-Path -LiteralPath $taskSecretFile) { Remove-Item -LiteralPath $taskSecretFile }
    if ($OrmOnly) {
        if ($Driver -eq 'pgsql') {
            $taskProbeFailed = $false
            foreach ($taskProbeMode in @('sync', 'hook', 'runtime')) {
                try {
                    Invoke-TaskProcess $taskPhp @('.github/scripts/probe-windows-pgsql.php', $taskProbeMode) (Join-Path $taskEvidence ('pdo-' + $taskProbeMode + '.log')) 30 $taskEnvironment | Out-Null
                } catch {
                    $taskProbeFailed = $true
                    Write-Output ('PDO PostgreSQL 接缝验证失败：' + $taskProbeMode)
                }
            }
            if ($taskProbeFailed) { throw 'PDO PostgreSQL 接缝验证未通过，详见 pdo-*.log。' }
        }
        foreach ($taskMode in @('php', 'native')) {
            Invoke-TaskProcess $taskPhp @('tests/orm-suite-consumer.php', $Driver, ('--' + $taskMode)) (Join-Path $taskEvidence ('orm-' + $taskMode + '.log')) 2400 $taskEnvironment | Out-Null
        }
    } else {
        Invoke-TaskProcess $taskPhp @('tests/iot-identity.php', '--php', $Driver, '--app') (Join-Path $taskEvidence 'development.log') 180 $taskEnvironment | Out-Null
        Invoke-TaskProcess $taskPhp @('tests/iot-identity.php', 'build/app/type-app.exe', $Driver, '--app') (Join-Path $taskEvidence 'native.log') 180 $taskEnvironment | Out-Null
        Invoke-TaskProcess $taskPhp @('tests/application-template.php', $Driver, '--onboarding', '--native', '--package') (Join-Path $taskEvidence 'onboarding.log') 2400 $taskEnvironment | Out-Null
    }
    $taskPassed = $true
} finally {
    if ($null -ne $taskServer) {
        try {
            if (!$taskServer.Process.HasExited) {
                Invoke-TaskProcess (Join-Path $taskBin 'mysqladmin.exe') @('--no-defaults', '--protocol=TCP', '--host=127.0.0.1', "--port=$taskPort", '--user=root', 'shutdown') (Join-Path $taskEvidence 'stop.log') 30 @{ MYSQL_PWD=$taskPassword } | Out-Null
            }
            Complete-TaskProcess $taskServer 30 (Join-Path $taskEvidence 'server.log') | Out-Null
        } catch {
            $taskCleanupPassed = $false
            if (!$taskServer.Process.HasExited) { $taskServer.Process.Kill($true); $taskServer.Process.WaitForExit(10000) | Out-Null }
        } finally { $taskServer.Process.Dispose() }
    }
    if ($taskPgStarted -and (Test-Path -LiteralPath (Join-Path $taskData 'postmaster.pid'))) {
        try { Invoke-TaskProcess (Join-Path $taskBin 'pg_ctl.exe') @('-D', $taskData, '-m', 'fast', '-w', '-t', '30', 'stop') (Join-Path $taskEvidence 'stop.log') 45 | Out-Null } catch { $taskCleanupPassed = $false }
    }
    if ($Driver -eq 'pgsql' -and (Test-Path -LiteralPath (Join-Path $taskWork 'postgres.log'))) {
        $taskServerLog = [string](Get-Content -LiteralPath (Join-Path $taskWork 'postgres.log') -Raw)
        foreach ($taskSecret in $script:taskSecrets) { $taskServerLog = $taskServerLog.Replace($taskSecret, '<REDACTED>') }
        [IO.File]::WriteAllText((Join-Path $taskEvidence 'server.log'), $taskServerLog, [Text.UTF8Encoding]::new($false))
    }
    if (Test-Path -LiteralPath $taskSecretFile) { Remove-Item -LiteralPath $taskSecretFile }
    $taskRecord = @{ platform='Windows'; driver=$Driver; version=$taskSource.version; archive_sha256=$taskSource.sha256; scope=$(if ($OrmOnly) { 'isolated ORM Composer consumption, PHP/AOT and source removal' } else { 'native dedicated database process, development/AOT and isolated template package' }); passed=($taskPassed -and $taskCleanupPassed); owned_process_cleanup=$taskCleanupPassed; installed_service=$false }
    $taskRecord | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $taskEvidence 'verification.json') -Encoding utf8
    if (!$taskCleanupPassed) { throw '本轮数据库未正常清理，不能记作通过。' }
}
