# Compare every ACE byte and control flag; Windows may reorder an all-inherited allow-only DACL.
function Get-TypeAppComparableDacl([Security.AccessControl.RawSecurityDescriptor]$Descriptor) {
    $taskFlags = [int]$Descriptor.ControlFlags -band (-bnot [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited)
    $Descriptor.SetFlags([Security.AccessControl.ControlFlags]$taskFlags)
    $taskAcl = $Descriptor.DiscretionaryAcl
    if ($null -eq $taskAcl) {
        return $Descriptor.GetSddlForm([Security.AccessControl.AccessControlSections]::Access)
    }
    $taskAces = @()
    foreach ($taskAce in $taskAcl) {
        # Deny, explicit, object-specific and callback ACEs retain their exact original order.
        if ($taskAce.AceType -ne [Security.AccessControl.AceType]::AccessAllowed -or
            !([int]$taskAce.AceFlags -band [int][Security.AccessControl.AceFlags]::Inherited)) {
            return $Descriptor.GetSddlForm([Security.AccessControl.AccessControlSections]::Access)
        }
        $taskBytes = New-Object byte[] $taskAce.BinaryLength
        $taskAce.GetBinaryForm($taskBytes, 0)
        $taskAces += [Convert]::ToBase64String($taskBytes)
    }
    return ([string]$taskFlags + ':' + [string]$taskAcl.Revision + ':' + (($taskAces | Sort-Object -CaseSensitive) -join '|'))
}
