$ErrorActionPreference='Stop'
$sillageRoot=Split-Path -Parent $PSScriptRoot
$sillageRuntime=Join-Path $sillageRoot '.runtime'
. "$PSScriptRoot/Process-Ownership.ps1"
if(-not(Test-Path -LiteralPath "$sillageRuntime/services.json")) { Write-Host 'Aucun processus enregistré par le lanceur. Aucun arrêt effectué.'; exit }
$sillageRemaining=@()
$sillageServices=@(Read-SillageServices -Path "$sillageRuntime/services.json")
[array]::Reverse($sillageServices)
foreach($sillageService in $sillageServices) {
    $sillageProcess=Get-Process -Id $sillageService.Id -ErrorAction SilentlyContinue
    if(-not $sillageProcess) {continue}
    $sillageMeta=Get-CimInstance Win32_Process -Filter "ProcessId=$($sillageService.Id)"
    if($sillageProcess.StartTime.ToUniversalTime().ToString('o') -ne $sillageService.Started -or $sillageProcess.Path -ne $sillageService.Executable -or $sillageMeta.CommandLine -notlike "*$($sillageService.Marker)*") {
        Write-Warning "Identité du processus $($sillageService.Id) différente. Aucun arrêt effectué."
        $sillageRemaining+=$sillageService; continue
    }
    try {
        if($sillageService.Marker -eq 'apache-local.conf') {
            Stop-SillageApache -ProcessId $sillageService.Id -Started $sillageService.Started -Executable $sillageService.Executable -Marker $sillageService.Marker
        } else {
            $sillageAdmin=Get-Content -Raw -LiteralPath "$sillageRuntime/mysql-admin.json" | ConvertFrom-Json
            $sillageOptionPath=Join-Path $sillageRuntime 'mysql-stop.cnf'
            try {
                [IO.File]::WriteAllText($sillageOptionPath,"[client]`nuser=root`npassword=$($sillageAdmin.password)`nhost=127.0.0.1`nport=3338`n",[Text.UTF8Encoding]::new($false))
                & "$sillageRuntime/mysql-8.4.13-winx64/bin/mysqladmin.exe" "--defaults-extra-file=$sillageOptionPath" --protocol=tcp shutdown
                if($LASTEXITCODE -ne 0) {throw 'Arrêt MySQL incomplet'}
            } finally { if(Test-Path -LiteralPath $sillageOptionPath) {Remove-Item -LiteralPath $sillageOptionPath} }
        }
        $sillageProcess.WaitForExit(15000) | Out-Null
        if(-not $sillageProcess.HasExited) {throw 'Le processus est encore actif'}
    } catch { Write-Warning $_; $sillageRemaining+=$sillageService }
}
ConvertTo-Json -InputObject @($sillageRemaining) -Depth 4 | Set-Content -LiteralPath "$sillageRuntime/services.json" -Encoding utf8
Write-Host 'Arrêt terminé. Les ressources dont l arrêt a échoué restent enregistrées.'
