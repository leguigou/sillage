$ErrorActionPreference='Stop'
$sillageGitRoot=Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $sillageGitRoot
$sillageGitPhp=(Get-Command php.exe).Source
$sillageGitRouter=Join-Path $sillageGitRoot 'tests/git_router.php'
if(Get-NetTCPConnection -State Listen -LocalPort 8089 -ErrorAction SilentlyContinue){throw 'Port 8089 occupé : le test Git nécessite son propre serveur isolé.'}
& $sillageGitPhp -c .runtime/php-config/php.ini tests/run.php fixtures
if($LASTEXITCODE -ne 0){throw 'Fixtures Git isolées en échec'}
$sillageGitServer=Start-Process -FilePath $sillageGitPhp -ArgumentList @('-c',"$sillageGitRoot/.runtime/php-config/php.ini",'-S','127.0.0.1:8089','-t',"$sillageGitRoot/public",$sillageGitRouter) -WorkingDirectory $sillageGitRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput "$sillageGitRoot/.runtime/git-server.out.log" -RedirectStandardError "$sillageGitRoot/.runtime/git-server.err.log"
try {
    $sillageGitReady=$false
    for($sillageGitAttempt=0;$sillageGitAttempt -lt 15;$sillageGitAttempt++){try{Invoke-WebRequest 'http://127.0.0.1:8089/login' -UseBasicParsing | Out-Null;$sillageGitReady=$true;break}catch{Start-Sleep -Milliseconds 500}}
    if(-not $sillageGitReady){throw 'Serveur Git isolé indisponible'}
    python tests/http_ticket_branches.py
    if($LASTEXITCODE -ne 0){throw 'Contrats HTTP des branches en échec'}
    python tests/http_git_monitoring.py
    if($LASTEXITCODE -ne 0){throw 'Suivi Git HTTP en échec'}
    python tests/http_filter_memory.py
    if($LASTEXITCODE -ne 0){throw 'Persistance des filtres en échec'}
} finally {
    $sillageGitCurrent=Get-Process -Id $sillageGitServer.Id -ErrorAction SilentlyContinue
    if($sillageGitCurrent) {
        $sillageGitMeta=Get-CimInstance Win32_Process -Filter "ProcessId=$($sillageGitServer.Id)"
        if($sillageGitCurrent.StartTime -ne $sillageGitServer.StartTime -or $sillageGitCurrent.Path -ne $sillageGitServer.Path -or $sillageGitMeta.CommandLine -notlike "*$sillageGitRouter*"){throw 'Identité du serveur Git de test non confirmée : aucun arrêt.'}
        Stop-Process -Id $sillageGitCurrent.Id
        $sillageGitCurrent.WaitForExit(15000) | Out-Null
    }
}
