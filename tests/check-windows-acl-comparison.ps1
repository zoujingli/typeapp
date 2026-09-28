$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'windows-acl-comparison.ps1')
# Use the actual Node DACL; only equivalent inherited allow ordering may differ.
$taskExpectedDacl = 'D:(A;ID;0x1200a9;;;BU)(A;ID;0x1200a9;;;AU)(A;ID;FA;;;BA)(A;ID;FA;;;SY)'
$taskReorderedDacl = 'D:AI(A;ID;0x1200a9;;;AU)(A;ID;FA;;;SY)(A;ID;FA;;;BA)(A;ID;0x1200a9;;;BU)'
$taskExpectedKey = Get-TypeAppComparableDacl ([Security.AccessControl.RawSecurityDescriptor]::new($taskExpectedDacl))
if ($taskExpectedKey -cne (Get-TypeAppComparableDacl ([Security.AccessControl.RawSecurityDescriptor]::new($taskReorderedDacl)))) {
    throw 'Equivalent inherited allow entries must compare equal.'
}
foreach ($taskChangedDacl in @(
    $taskExpectedDacl.Replace('0x1200a9;;;BU', 'FA;;;BU'),
    $taskExpectedDacl.Replace('(A;ID;FA;;;SY)', ''),
    ($taskExpectedDacl + '(A;ID;FA;;;SY)'),
    $taskExpectedDacl.Replace('(A;ID;0x1200a9;;;BU)', '(A;;0x1200a9;;;BU)'),
    $taskExpectedDacl.Replace('D:', 'D:P'),
    $taskExpectedDacl.Replace('(A;ID;FA;;;BA)', '(D;ID;FA;;;BA)')
)) {
    if ($taskExpectedKey -ceq (Get-TypeAppComparableDacl ([Security.AccessControl.RawSecurityDescriptor]::new($taskChangedDacl)))) {
        throw 'Changed ACL rights, entries, inheritance or protection must be rejected.'
    }
}
$taskDenyFirst = 'D:(D;ID;FR;;;BU)(A;ID;FR;;;BU)'
$taskAllowFirst = 'D:(A;ID;FR;;;BU)(D;ID;FR;;;BU)'
if ((Get-TypeAppComparableDacl ([Security.AccessControl.RawSecurityDescriptor]::new($taskDenyFirst))) -ceq
    (Get-TypeAppComparableDacl ([Security.AccessControl.RawSecurityDescriptor]::new($taskAllowFirst)))) {
    throw 'Deny ordering must remain significant.'
}
Write-Output ('ACL comparison checks passed on PowerShell ' + $PSVersionTable.PSVersion.ToString())
