function Stop-SillageApache {
    param([int]$ProcessId,[string]$Started,[string]$Executable,[string]$Marker)
    $owned=Get-Process -Id $ProcessId -ErrorAction SilentlyContinue
    if(-not $owned){return}
    $meta=Get-CimInstance Win32_Process -Filter "ProcessId=$ProcessId"
    if($owned.StartTime.ToUniversalTime().ToString('o') -ne $Started -or $owned.Path -ne $Executable -or $meta.CommandLine -notlike "*$Marker*"){throw "Identité du processus Apache $ProcessId non confirmée. Aucun arrêt."}
    $ownedChildren=@()
    foreach($childMeta in @(Get-CimInstance Win32_Process -Filter "ParentProcessId=$ProcessId")){
        $child=Get-Process -Id $childMeta.ProcessId -ErrorAction SilentlyContinue
        if($child -and $child.Path -eq $Executable -and $child.StartTime -ge $owned.StartTime){$ownedChildren+=@{Id=$child.Id;Started=$child.StartTime.ToUniversalTime().ToString('o');Executable=$child.Path}}
    }
    # Apache Windows lancé en console n est pas un service -k shutdown.
    # Arrêt des seuls processus dont la filiation et l identité ont été établies.
    Stop-Process -Id $owned.Id
    foreach($childIdentity in $ownedChildren){
        $child=Get-Process -Id $childIdentity.Id -ErrorAction SilentlyContinue
        if($child -and $child.StartTime.ToUniversalTime().ToString('o') -eq $childIdentity.Started -and $child.Path -eq $childIdentity.Executable){Stop-Process -Id $child.Id}
    }
    $owned.WaitForExit(15000) | Out-Null
    if(-not $owned.HasExited){throw 'Apache est encore actif.'}
}
function Read-SillageServices {
    param([string]$Path)
    if(-not(Test-Path -LiteralPath $Path)){return}
    $parsed=ConvertFrom-Json -InputObject (Get-Content -Raw -LiteralPath $Path)
    foreach($record in $parsed){
        if(-not $record.Id -or -not $record.Started -or -not $record.Executable -or -not $record.Marker -or $record.Id -is [array]){throw 'Registre de processus invalide. Conserver le fichier pour inspection.'}
        Write-Output $record
    }
}
