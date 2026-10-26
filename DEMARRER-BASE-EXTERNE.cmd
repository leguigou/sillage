@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -File "%~dp0scripts\Start-Local.ps1" -ExternalDatabase -OpenBrowser
if errorlevel 1 pause
