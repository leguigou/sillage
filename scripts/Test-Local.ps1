$ErrorActionPreference='Stop'
$sillageRoot=Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $sillageRoot
$sillagePhp=(Get-Command php.exe).Source
$sillageIni="$sillageRoot/.runtime/php-config/php.ini"
. "$PSScriptRoot/Process-Ownership.ps1"
if(Get-NetTCPConnection -State Listen -LocalPort 8089 -ErrorAction SilentlyContinue){throw 'Le port 8089 doit être libre avant de préparer la base isolée.'}
& $sillagePhp -c $sillageIni "$sillageRoot/tests/prepare-local.php"
if($LASTEXITCODE -ne 0){throw 'Préparation de test impossible'}
& $sillagePhp -c $sillageIni "$sillageRoot/tests/run.php"
if($LASTEXITCODE -ne 0){throw 'Tests de contrats en échec'}
$sillageSuites=@(
    @{Configuration='apache-test.conf'; Script='http_workflows.py'; ScriptArguments=@()},
    @{Configuration='apache-test.conf'; Script='http_mcp.py'; ScriptArguments=@()},
    @{Configuration='apache-company-test.conf'; Script='http_company.py'; ScriptArguments=@()},
    @{Configuration='apache-company-open-test.conf'; Script='http_company.py'; ScriptArguments=@('--open')}
)
foreach($sillageSuite in $sillageSuites) {
    & $sillagePhp -c $sillageIni "$sillageRoot/tests/run.php" fixtures
    if($LASTEXITCODE -ne 0){throw 'Fixtures en échec'}
    if(Get-NetTCPConnection -State Listen -LocalPort 8089 -ErrorAction SilentlyContinue){throw 'Le port 8089 doit être libre pour créer un serveur de test dont le script est propriétaire.'}
    $sillageConfiguration=Join-Path "$sillageRoot/.runtime" $sillageSuite.Configuration
    $sillageTest=Start-Process -FilePath "$sillageRoot/.runtime/Apache24/bin/httpd.exe" -ArgumentList @('-f',$sillageConfiguration) -WorkingDirectory $sillageRoot -WindowStyle Hidden -PassThru
    try {
        $sillageReady=$false
        for($sillageAttempt=0;$sillageAttempt -lt 15;$sillageAttempt++){try{Invoke-WebRequest -Uri 'http://127.0.0.1:8089/login' -UseBasicParsing | Out-Null;$sillageReady=$true;break}catch{Start-Sleep -Seconds 1}}
        if(-not $sillageReady){throw 'Apache de test indisponible'}
        $sillageScriptArguments=$sillageSuite.ScriptArguments
        python "$sillageRoot/tests/$($sillageSuite.Script)" @sillageScriptArguments
        if($LASTEXITCODE -ne 0){throw 'Tests HTTP en échec'}
    } finally {
        $sillageCurrent=Get-Process -Id $sillageTest.Id -ErrorAction SilentlyContinue
        if($sillageCurrent -and $sillageCurrent.StartTime -eq $sillageTest.StartTime -and $sillageCurrent.Path -eq $sillageTest.Path){Stop-SillageApache -ProcessId $sillageTest.Id -Started $sillageTest.StartTime.ToUniversalTime().ToString('o') -Executable $sillageTest.Path -Marker $sillageSuite.Configuration}
    }
}
