@echo off
title TGG Setup Hosts File

:: Check for Administrator privileges
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo Requesting administrative privileges to modify hosts file...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)

:: We are elevated here!
echo Modifying hosts file...
findstr /I /C:"tgg.test" "%windir%\System32\drivers\etc\hosts" >nul
if %errorLevel% neq 0 (
    echo. >> "%windir%\System32\drivers\etc\hosts"
    echo 127.0.0.1 tgg.test >> "%windir%\System32\drivers\etc\hosts"
    echo tgg.test added to hosts file.
) else (
    echo tgg.test is already in the hosts file.
)

echo Done! Press any key to exit.
pause >nul
