$ErrorActionPreference='Stop'
$sillageGitRoot=Split-Path -Parent $PSScriptRoot
. "$PSScriptRoot/Process-Ownership.ps1"
$sillageGitServices=Join-Path $sillageGitRoot '.runtime/services.json'
$sillageGitExisting=@(Read-SillageServices -Path $sillageGitServices)
foreach($sillageGitOwned in $sillageGitExisting | Where-Object Marker -eq 'git:watch') {
    $sillageGitProcess=Get-Process -Id $sillageGitOwned.Id -ErrorAction SilentlyContinue
    if($sillageGitProcess) {
        $sillageGitMeta=Get-CimInstance Win32_Process -Filter "ProcessId=$($sillageGitOwned.Id)"
        if($sillageGitProcess.StartTime.ToUniversalTime().ToString('o') -eq $sillageGitOwned.Started -and $sillageGitProcess.Path -eq $sillageGitOwned.Executable -and $sillageGitMeta.CommandLine -like '*git:watch*' -and $sillageGitMeta.CommandLine -like "*$sillageGitRoot*console.php*") {Write-Host 'Synchronisation Git déjà démarrée.';return}
    }
}
if(Test-Path -LiteralPath "$sillageGitRoot/.runtime/git-worker-launch.json") {throw 'Un lancement Git précédent reste à vérifier dans .runtime/git-worker-launch.json. Aucun nouveau processus lancé.'}
$sillageGitPhp=(Get-Command php.exe).Source
$sillageGitArgs=@('-c',('"'+(Join-Path $sillageGitRoot '.runtime/php-config/php.ini')+'"'),('"'+(Join-Path $sillageGitRoot 'bin/console.php')+'"'),'git:watch')
$sillageGitProcess=Start-Process -FilePath $sillageGitPhp -ArgumentList $sillageGitArgs -WorkingDirectory $sillageGitRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput "$sillageGitRoot/.runtime/git-worker.out.log" -RedirectStandardError "$sillageGitRoot/.runtime/git-worker.err.log"
# Start-Process may return before Windows exposes the executable module.
# The launch PID/time stay owned even when executable lookup is temporarily unavailable.
$sillageGitStarted=$sillageGitProcess.StartTime.ToUniversalTime().ToString('o')
$sillageGitRecord=@{Id=$sillageGitProcess.Id;Started=$sillageGitStarted;Executable=$sillageGitPhp;Marker='git:watch'}
ConvertTo-Json -InputObject $sillageGitRecord | Set-Content -LiteralPath "$sillageGitRoot/.runtime/git-worker-launch.json" -Encoding utf8
$sillageGitVerified=$false
for($sillageGitAttempt=0;$sillageGitAttempt -lt 20;$sillageGitAttempt++) {
    $sillageGitCurrent=Get-Process -Id $sillageGitProcess.Id -ErrorAction SilentlyContinue
    $sillageGitMeta=Get-CimInstance Win32_Process -Filter "ProcessId=$($sillageGitProcess.Id)"
    if($sillageGitCurrent -and $sillageGitCurrent.StartTime.ToUniversalTime().ToString('o') -eq $sillageGitStarted -and $sillageGitCurrent.Path -eq $sillageGitPhp -and $sillageGitMeta.CommandLine -like "*$sillageGitRoot*console.php*git:watch*") {$sillageGitVerified=$true;break}
    if($sillageGitProcess.HasExited){throw 'Le service Git a quitté. Consultez .runtime/git-worker.err.log ; sa trace de lancement est conservée.'}
    Start-Sleep -Milliseconds 100
}
if(-not $sillageGitVerified){throw 'Identité du worker Git non confirmée. Trace conservée dans .runtime/git-worker-launch.json ; ne pas relancer avant vérification.'}
ConvertTo-Json -InputObject @($sillageGitExisting+$sillageGitRecord) -Depth 4 | Set-Content -LiteralPath $sillageGitServices -Encoding utf8
Remove-Item -LiteralPath "$sillageGitRoot/.runtime/git-worker-launch.json"
Write-Host 'Synchronisation Git démarrée en arrière-plan.'
