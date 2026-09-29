Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (!$IsWindows -or $env:GITHUB_ACTIONS -ne 'true') { throw '此夹具仅用于原生 Windows Actions。' }
# 固定的 Cygwin Redis 只运行专用测试服务，不安装系统服务、不进入应用或重建 SDK。
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$taskWork = Join-Path $taskRoot 'build/windows-test-redis'
if (Test-Path -LiteralPath $taskWork) { throw '不能覆盖既有 Redis 测试工具。' }
[IO.Directory]::CreateDirectory($taskWork) | Out-Null
$taskArchive = Join-Path $taskWork 'redis.zip'
$taskUrl = 'https://github.com/redis-windows/redis-windows/releases/download/8.10.2/Redis-8.10.2-Windows-x64-cygwin.zip'
$taskSha = '6de5cc7f5adbf97b5928b13766383d4ad424626ef3d8b313ffff12d820ec6fc1'
Invoke-WebRequest -Uri $taskUrl -OutFile $taskArchive
if ((Get-FileHash -LiteralPath $taskArchive -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskSha) { throw 'Redis 测试归档摘要不符。' }
Expand-Archive -LiteralPath $taskArchive -DestinationPath (Join-Path $taskWork 'tools')
$taskServers = @(Get-ChildItem -LiteralPath (Join-Path $taskWork 'tools') -Recurse -Filter redis-server.exe -File)
if ($taskServers.Count -ne 1) { throw 'Redis 测试归档没有唯一服务器。' }
Add-Content -LiteralPath $env:GITHUB_ENV -Value ('TYPE_REDIS_SERVER=' + $taskServers[0].FullName) -Encoding utf8
@{url=$taskUrl; sha256=$taskSha; scope='test-only Cygwin service; not a deployment dependency'} |
    ConvertTo-Json | Set-Content (Join-Path $taskWork 'source.json') -Encoding utf8
