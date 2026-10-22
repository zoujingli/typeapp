param(
    [Parameter(Mandatory)][ValidateSet('freeze', 'resume', 'terminate')][string]$Action,
    [Parameter(Mandatory)][int]$TargetProcessId,
    [Parameter(Mandatory)][string]$Artifact,
    [Parameter(Mandatory)][string]$Receipt
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# 仅由转配故障夹具控制自己启动的原程序；记录启动时间，拒绝 PID 复用或其他可执行文件。
if (!$IsWindows -or $TargetProcessId -le 0 -or $TargetProcessId -eq $PID) { throw '需要本轮 Windows 子进程。' }
$taskBuild = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../build')) + [IO.Path]::DirectorySeparatorChar
$taskReceipt = [IO.Path]::GetFullPath($Receipt)
$taskArtifact = [IO.Path]::GetFullPath($Artifact)
if (!$taskReceipt.StartsWith($taskBuild, [StringComparison]::OrdinalIgnoreCase) -or
    !$taskArtifact.StartsWith($taskBuild, [StringComparison]::OrdinalIgnoreCase) -or
    !(Test-Path -LiteralPath $taskArtifact -PathType Leaf)) { throw '故障对象及回执必须属于本轮 build。' }

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class TypeAppProcessFault {
    [DllImport("ntdll.dll")] private static extern int NtSuspendProcess(IntPtr process);
    [DllImport("ntdll.dll")] private static extern int NtResumeProcess(IntPtr process);
    public static void Control(IntPtr process, bool suspend) {
        int status = suspend ? NtSuspendProcess(process) : NtResumeProcess(process);
        if (status != 0) throw new InvalidOperationException("Process control failed: " + status);
    }
}
'@

function Read-TaskProcess([int]$ProcessId) {
    try { return [Diagnostics.Process]::GetProcessById($ProcessId) }
    catch [ArgumentException] { return $null }
}

$taskProcess = Read-TaskProcess $TargetProcessId
if ($null -eq $taskProcess) {
    if ($Action -eq 'resume') { @{ status='absent'; pid=$TargetProcessId } | ConvertTo-Json -Compress; exit 0 }
    throw '故障目标已经退出，不能把未执行操作记为通过。'
}
try {
    $taskTicks = [string]$taskProcess.StartTime.ToUniversalTime().Ticks
    if (![string]::Equals($taskProcess.MainModule.FileName, $taskArtifact, [StringComparison]::OrdinalIgnoreCase)) { throw '故障目标不是本轮程序。' }
    if ($Action -eq 'freeze') {
        if (Test-Path -LiteralPath $taskReceipt) { throw '不能覆盖已有冻结回执。' }
        [TypeAppProcessFault]::Control($taskProcess.Handle, $true)
        try {
            $taskRecord = @{ status='frozen'; pid=$TargetProcessId; started_ticks=$taskTicks }
            [IO.File]::WriteAllText($taskReceipt, ($taskRecord | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
        } catch {
            [TypeAppProcessFault]::Control($taskProcess.Handle, $false)
            throw
        }
    } else {
        $taskRecord = Get-Content -LiteralPath $taskReceipt -Raw | ConvertFrom-Json -AsHashtable
        if ($taskRecord.pid -ne $TargetProcessId -or $taskRecord.started_ticks -cne $taskTicks -or $taskRecord.status -cne 'frozen') { throw '冻结身份已变化。' }
        if ($Action -eq 'resume') {
            [TypeAppProcessFault]::Control($taskProcess.Handle, $false)
            $taskRecord.status = 'resumed'
        } else {
            $taskRows = @(Get-CimInstance -ClassName Win32_Process -Property ProcessId, ParentProcessId)
            $taskIds = [Collections.Generic.HashSet[int]]::new()
            [void]$taskIds.Add($TargetProcessId)
            do {
                $taskCount = $taskIds.Count
                foreach ($taskRow in $taskRows) {
                    if ($taskIds.Contains([int]$taskRow.ParentProcessId)) { [void]$taskIds.Add([int]$taskRow.ProcessId) }
                }
            } while ($taskIds.Count -ne $taskCount)
            $taskOwned = @{}
            foreach ($taskChildId in $taskIds) {
                $taskChild = Read-TaskProcess $taskChildId
                if ($null -eq $taskChild) { continue }
                try { $taskOwned[[string]$taskChildId] = [string]$taskChild.StartTime.ToUniversalTime().Ticks }
                finally { $taskChild.Dispose() }
            }
            # .NET 的树终止由操作系统执行，不以父进程退出推定持久 worker 已退出。
            $taskProcess.Kill($true)
            if (!$taskProcess.WaitForExit(10000)) { throw '根进程没有确认退出。' }
            foreach ($taskChildId in $taskOwned.Keys) {
                $taskChild = Read-TaskProcess ([int]$taskChildId)
                if ($null -eq $taskChild) { continue }
                try {
                    if ([string]$taskChild.StartTime.ToUniversalTime().Ticks -ceq $taskOwned[$taskChildId] -and !$taskChild.WaitForExit(5000)) {
                        throw '本轮子进程仍存在，不能登记硬隔离。'
                    }
                } finally { $taskChild.Dispose() }
            }
            $taskRecord.status = 'terminated'
            $taskRecord.owned_processes = $taskOwned
            $taskRecord.all_owned_pids_absent = $true
        }
        [IO.File]::WriteAllText($taskReceipt, ($taskRecord | ConvertTo-Json -Depth 5 -Compress), [Text.UTF8Encoding]::new($false))
    }
    $taskRecord | ConvertTo-Json -Depth 5 -Compress
} finally { $taskProcess.Dispose() }
