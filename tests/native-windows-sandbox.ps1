param(
    [Parameter(Mandatory = $true)][ValidateSet('prepare', 'restore')][string]$Operation,
    [Parameter(Mandatory = $true)][string]$Specification
)
$ErrorActionPreference = 'Stop'
# 普通子进程可能继承 PowerShell 7 的模块搜索路径；ACL 必须使用当前系统解释器的模块。
Import-Module (Join-Path $PSHOME 'Modules/Microsoft.PowerShell.Security/Microsoft.PowerShell.Security.psd1') -ErrorAction Stop
. (Join-Path $PSScriptRoot 'windows-acl-comparison.ps1')
# ACL 只涉及本轮唯一 SID；原控制器与其他账号的权限保持原样。
# 本脚本保留 UTF-8 BOM，兼容系统 PowerShell 5.1；JSON 也明确按 UTF-8 读取。
$taskSpec = Get-Content -LiteralPath $Specification -Raw -Encoding utf8 | ConvertFrom-Json
$taskLedger = $Specification + '.acl.json'
if ($Operation -eq 'restore') {
    if (!(Test-Path -LiteralPath $taskLedger)) { return }
    # PowerShell 5.1 将 JSON 数组作为一个管道对象输出；额外 @() 会产生嵌套数组。
    $taskEntries = Get-Content -LiteralPath $taskLedger -Raw -Encoding utf8 | ConvertFrom-Json
    # 先恢复祖先，再恢复后代的完整快照，避免后续继承传播覆盖已恢复的子目录。
    $taskEntries = @($taskEntries | Sort-Object { $_.path.Length })
    foreach ($taskEntry in $taskEntries) {
        if ($taskEntry.path -isnot [string] -or $taskEntry.sddl -isnot [string]) {
            throw 'ACL 恢复记录必须为路径与 SDDL 字符串。'
        }
        $taskAcl = Get-Acl -LiteralPath $taskEntry.path
        $taskAcl.SetSecurityDescriptorSddlForm($taskEntry.sddl, [Security.AccessControl.AccessControlSections]::Access)
        Set-Acl -LiteralPath $taskEntry.path -AclObject $taskAcl
    }
    $taskMismatches = @()
    foreach ($taskEntry in $taskEntries) {
        $taskRestored = (Get-Acl -LiteralPath $taskEntry.path).GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)
        $taskExpectedDescriptor = [Security.AccessControl.RawSecurityDescriptor]::new($taskEntry.sddl)
        $taskActualDescriptor = [Security.AccessControl.RawSecurityDescriptor]::new($taskRestored)
        # Set-Acl 会设置 AI 标记，并可重排全部为继承允许项的 DACL；逐条权限与数量不变。
        # 含拒绝、显式或特殊 ACE 时顺序仍有意义，继续严格比较。
        try {
            $taskActualKey = Get-TypeAppComparableDacl $taskActualDescriptor
            $taskExpectedKey = Get-TypeAppComparableDacl $taskExpectedDescriptor
        } catch {
            throw ('ACL comparison failed: ' + $_.Exception.Message + "`n" + $_.ScriptStackTrace)
        }
        if ($taskActualKey -cne $taskExpectedKey) {
            $taskMismatches += @{path=$taskEntry.path; expected=$taskEntry.sddl; actual=$taskRestored}
        }
    }
    if ($taskMismatches.Count -gt 0) {
        # 保留每个真实差异，区分继承标记变化和权限残留，不能用首项失败掩盖后代状态。
        ConvertTo-Json -InputObject $taskMismatches -Depth 5 |
            Set-Content -LiteralPath ($Specification + '.restore.json') -Encoding utf8
        throw '原始 ACL 回读不一致，保留恢复记录与逐项差异。'
    }
    Remove-Item -LiteralPath $taskLedger
    return
}
if (Test-Path -LiteralPath $taskLedger) { throw '本轮 ACL 尚未恢复，不能覆盖恢复记录。' }
$taskSid = [Security.Principal.SecurityIdentifier]::new($taskSpec.sid)
$taskEntries = @()
foreach ($taskChange in $taskSpec.changes) {
    $taskPath = [IO.Path]::GetFullPath($taskChange.path)
    if (!(Test-Path -LiteralPath $taskPath) -or (Get-Item -LiteralPath $taskPath).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw '隔离 ACL 目标缺失或包含重解析点。'
    }
    $taskAcl = Get-Acl -LiteralPath $taskPath
    $taskEntries += @{path=$taskPath; sddl=$taskAcl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)}
}
# 修改任意祖先前先保存全部原始 ACL，防止快照包含本轮已继承的限制 SID。
ConvertTo-Json -InputObject $taskEntries -Depth 5 | Set-Content -LiteralPath $taskLedger -Encoding utf8
foreach ($taskChange in $taskSpec.changes) {
    $taskPath = [IO.Path]::GetFullPath($taskChange.path)
    $taskAcl = Get-Acl -LiteralPath $taskPath
    $taskInheritance = if ((Get-Item -LiteralPath $taskPath).PSIsContainer) {
        [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    } else { [Security.AccessControl.InheritanceFlags]::None }
    $taskPropagation = [Security.AccessControl.PropagationFlags]::None
    if ($taskChange.access -eq 'deny-read') {
        # 禁止源码/工具文件的内容读取与执行；保留目录元数据供 PHP realpath 逐层解析。
        # 仅向文件传播拒绝，不能因父目录 ListDirectory 被拒绝而使已授权的数据目录失效。
        $taskRights = [Security.AccessControl.FileSystemRights]'ReadData,ExecuteFile'
        if ((Get-Item -LiteralPath $taskPath).PSIsContainer) {
            $taskInheritance = [Security.AccessControl.InheritanceFlags]::ObjectInherit
            $taskPropagation = [Security.AccessControl.PropagationFlags]::InheritOnly
        }
        $taskType = [Security.AccessControl.AccessControlType]::Deny
    } elseif ($taskChange.access -eq 'modify') {
        $taskRights = [Security.AccessControl.FileSystemRights]::Modify
        $taskType = [Security.AccessControl.AccessControlType]::Allow
    } elseif ($taskChange.access -eq 'read') {
        $taskRights = [Security.AccessControl.FileSystemRights]::ReadAndExecute
        $taskType = [Security.AccessControl.AccessControlType]::Allow
        $taskNoWrite = [Security.AccessControl.FileSystemRights]'Write,Delete,DeleteSubdirectoriesAndFiles'
        $taskAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($taskSid, $taskNoWrite,
            $taskInheritance, [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Deny))
    } else { throw '隔离 ACL 类型无效。' }
    $taskAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($taskSid, $taskRights,
        $taskInheritance, $taskPropagation, $taskType))
    Set-Acl -LiteralPath $taskPath -AclObject $taskAcl
}
