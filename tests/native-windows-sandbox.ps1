param(
    [Parameter(Mandatory = $true)][ValidateSet('prepare', 'restore')][string]$Operation,
    [Parameter(Mandatory = $true)][string]$Specification
)
$ErrorActionPreference = 'Stop'
# ACL 只涉及本轮唯一 SID；原控制器与其他账号的权限保持原样。
$taskSpec = Get-Content -LiteralPath $Specification -Raw | ConvertFrom-Json
$taskLedger = $Specification + '.acl.json'
if ($Operation -eq 'restore') {
    if (!(Test-Path -LiteralPath $taskLedger)) { return }
    $taskEntries = @(Get-Content -LiteralPath $taskLedger -Raw | ConvertFrom-Json)
    [array]::Reverse($taskEntries)
    foreach ($taskEntry in $taskEntries) {
        $taskAcl = Get-Acl -LiteralPath $taskEntry.path
        $taskAcl.SetSecurityDescriptorSddlForm($taskEntry.sddl, [Security.AccessControl.AccessControlSections]::Access)
        Set-Acl -LiteralPath $taskEntry.path -AclObject $taskAcl
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
    # 先保存恢复信息，再写 ACL；异常或中断后的控制端仍可恢复。
    ConvertTo-Json -InputObject $taskEntries -Depth 5 | Set-Content -LiteralPath $taskLedger -Encoding utf8
    $taskInheritance = if ((Get-Item -LiteralPath $taskPath).PSIsContainer) {
        [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    } else { [Security.AccessControl.InheritanceFlags]::None }
    if ($taskChange.access -eq 'deny-read') {
        $taskRights = [Security.AccessControl.FileSystemRights]::ReadAndExecute
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
        $taskInheritance, [Security.AccessControl.PropagationFlags]::None, $taskType))
    Set-Acl -LiteralPath $taskPath -AclObject $taskAcl
}
