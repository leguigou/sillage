param([switch]$OpenBrowser)
$ErrorActionPreference = 'Stop'
$sillageRoot = Split-Path -Parent $PSScriptRoot
$sillageRuntime = Join-Path $sillageRoot '.runtime'
. "$PSScriptRoot/Process-Ownership.ps1"
$sillageUrl = 'http://127.0.0.1:8088'
Set-Location -LiteralPath $sillageRoot
New-Item -ItemType Directory -Path $sillageRuntime -Force | Out-Null
function Get-VerifiedArchive($Url, $Destination, $Algorithm, $Expected) {
    if (-not (Test-Path -LiteralPath $Destination)) { Invoke-WebRequest -Uri $Url -OutFile $Destination }
    if ((Get-FileHash -LiteralPath $Destination -Algorithm $Algorithm).Hash -ne $Expected) { throw "Archive non conforme : $Destination" }
}
try { $sillagePhp = (Get-Command php.exe -ErrorAction Stop).Source } catch { throw 'PHP 8.3+ Thread Safe doit être installé et disponible sur PATH (pdo_mysql, mbstring, curl, openssl, fileinfo).' }
$sillagePhpDir = Split-Path -Parent $sillagePhp
if (-not (Test-Path -LiteralPath (Join-Path $sillagePhpDir 'php8apache2_4.dll'))) { throw 'Utilisez une version PHP Windows Thread Safe avec php8apache2_4.dll.' }
if (-not (Test-Path -LiteralPath "$sillageRuntime/mysql-8.4.13-winx64/bin/mysqld.exe")) {
    Write-Host 'Téléchargement de MySQL Community 8.4.13 (archive officielle)…'
    Get-VerifiedArchive 'https://dev.mysql.com/get/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip' "$sillageRuntime/mysql.zip" 'MD5' '7B94489D5524DEA9B27097068BEA25A8'
    Expand-Archive -LiteralPath "$sillageRuntime/mysql.zip" -DestinationPath $sillageRuntime
}
if (-not (Test-Path -LiteralPath "$sillageRuntime/Apache24/bin/httpd.exe")) {
    Write-Host 'Téléchargement d Apache Lounge 2.4.69 (archive vérifiée)…'
    Get-VerifiedArchive 'https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.69-261002-Win64-VS18.zip' "$sillageRuntime/apache.zip" 'SHA256' '77D715D0D9CEE731E2251E7DDC2090A7A8E6D41FEDEF2E9827CD918593C5A652'
    Expand-Archive -LiteralPath "$sillageRuntime/apache.zip" -DestinationPath $sillageRuntime
}
$sillageUnixRoot = $sillageRoot.Replace('\','/')
$sillageUnixPhp = $sillagePhpDir.Replace('\','/')
New-Item -ItemType Directory -Path "$sillageRuntime/php-config","$sillageRoot/storage/sessions","$sillageRoot/storage/uploads","$sillageRoot/storage/mail" -Force | Out-Null
$sillageIni = @"
extension_dir="$sillageUnixPhp/ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
display_errors=Off
log_errors=On
error_reporting=E_ALL
error_log="$sillageUnixRoot/.runtime/php-module.log"
date.timezone=UTC
upload_max_filesize=12M
post_max_size=14M
session.save_path="$sillageUnixRoot/storage/sessions"
session.use_strict_mode=1
"@
$sillageIniPath = "$sillageRuntime/php-config/php.ini"
if (Test-Path -LiteralPath $sillageIniPath) { if ((Get-Content -Raw -LiteralPath $sillageIniPath) -ne $sillageIni) { Copy-Item -LiteralPath $sillageIniPath -Destination "$sillageIniPath.backup" -Force } }
Set-Content -LiteralPath $sillageIniPath -Value $sillageIni -Encoding utf8
& $sillagePhp -c $sillageIniPath "$sillageRoot/bin/local-config.php"
if ($LASTEXITCODE -ne 0) { throw 'Échec de préparation de la configuration.' }
$sillageMysql = "$sillageRuntime/mysql-8.4.13-winx64/bin/mysqld.exe"
if (-not (Test-Path -LiteralPath "$sillageRuntime/mysql-data")) {
    & $sillageMysql --no-defaults --initialize-insecure "--basedir=$sillageUnixRoot/.runtime/mysql-8.4.13-winx64" "--datadir=$sillageUnixRoot/.runtime/mysql-data" --console
    if ($LASTEXITCODE -ne 0) { throw 'Initialisation MySQL impossible.' }
}
$sillageServices = @()
if (-not (Get-NetTCPConnection -State Listen -LocalPort 3338 -ErrorAction SilentlyContinue)) {
    $sillageMysqlArgs = @('--no-defaults', "--basedir=$sillageUnixRoot/.runtime/mysql-8.4.13-winx64", "--datadir=$sillageUnixRoot/.runtime/mysql-data", '--bind-address=127.0.0.1','--port=3338','--mysqlx=0','--console')
    if (Test-Path -LiteralPath "$sillageRuntime/bootstrap.sql") { $sillageMysqlArgs += "--init-file=$sillageUnixRoot/.runtime/bootstrap.sql" }
    $sillageProcess = Start-Process -FilePath $sillageMysql -ArgumentList $sillageMysqlArgs -WorkingDirectory $sillageRoot -WindowStyle Hidden -PassThru -RedirectStandardError "$sillageRuntime/mysql-error.log" -RedirectStandardOutput "$sillageRuntime/mysql-output.log"
    $sillageServices += @{Id=$sillageProcess.Id; Started=$sillageProcess.StartTime.ToUniversalTime().ToString('o'); Executable=$sillageProcess.Path; Marker='--port=3338'}
}
$sillageReady = $false
for ($sillageAttempt=0; $sillageAttempt -lt 30; $sillageAttempt++) {
    & $sillagePhp -c $sillageIniPath "$sillageRoot/bin/console.php" health 2>$null
    if ($LASTEXITCODE -eq 0) { $sillageReady=$true; break }
    Start-Sleep -Seconds 1
}
if (-not $sillageReady) { throw 'MySQL indisponible. Consultez .runtime/mysql-error.log et votre .env.' }
if (Test-Path -LiteralPath "$sillageRuntime/bootstrap.sql") { Remove-Item -LiteralPath "$sillageRuntime/bootstrap.sql" }
& $sillagePhp -c $sillageIniPath "$sillageRoot/bin/console.php" migrate
if ($LASTEXITCODE -ne 0) { throw 'Migration impossible. Aucun serveur web lancé.' }
& $sillagePhp -c $sillageIniPath "$sillageRoot/bin/console.php" seed
if ($LASTEXITCODE -ne 0) { throw 'Initialisation de la démonstration impossible.' }
if (-not (Get-NetTCPConnection -State Listen -LocalPort 8088 -ErrorAction SilentlyContinue)) {
    $sillageConfig = Get-Content -Raw -LiteralPath "$sillageRoot/deploy/apache-windows.conf.template"
    $sillageConfig=$sillageConfig.Replace('{{ROOT}}',$sillageUnixRoot).Replace('{{PHP}}',$sillageUnixPhp)
    Set-Content -LiteralPath "$sillageRuntime/apache-local.conf" -Value $sillageConfig -Encoding utf8
    & "$sillageRuntime/Apache24/bin/httpd.exe" -t -f "$sillageRuntime/apache-local.conf"
    if ($LASTEXITCODE -ne 0) { throw 'Configuration Apache invalide.' }
    $sillageProcess = Start-Process -FilePath "$sillageRuntime/Apache24/bin/httpd.exe" -ArgumentList @('-f',"$sillageRuntime/apache-local.conf") -WindowStyle Hidden -PassThru -WorkingDirectory $sillageRoot
    $sillageServices += @{Id=$sillageProcess.Id; Started=$sillageProcess.StartTime.ToUniversalTime().ToString('o'); Executable=$sillageProcess.Path; Marker='apache-local.conf'}
}
if ($sillageServices.Count -gt 0) {
    $sillageExisting=@(Read-SillageServices -Path "$sillageRuntime/services.json")
    ConvertTo-Json -InputObject @($sillageExisting+$sillageServices) -Depth 4 | Set-Content -LiteralPath "$sillageRuntime/services.json" -Encoding utf8
}
$sillageHttpReady=$false
for($sillageAttempt=0; $sillageAttempt -lt 15; $sillageAttempt++) {
    try { $sillageResponse=Invoke-WebRequest -Uri "$sillageUrl/login" -UseBasicParsing; if($sillageResponse.StatusCode -eq 200 -and $sillageResponse.Content -match 'Sillage') {$sillageHttpReady=$true; break} } catch {}
    Start-Sleep -Seconds 1
}
if(-not $sillageHttpReady) {throw 'Le port 8088 ne répond pas comme une application Sillage. Consultez les journaux Apache.'}
Write-Host "Sillage est prêt : $sillageUrl" -ForegroundColor Green
Write-Host 'Accès de démonstration : .runtime/credentials.txt. E-mails locaux : storage/mail.'
if($OpenBrowser) { Start-Process $sillageUrl }
