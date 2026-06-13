@echo off
title TGG HTTPS Reverse Proxy (Caddy)

cd /d "%~dp0"

:: Check for Administrator privileges
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo ============================================================
    echo   ERROR: ADMINISTRATIVE PRIVILEGES REQUIRED
    echo ============================================================
    echo   This script must be run as Administrator to allow Caddy to:
    echo   1. Bind to port 443 (standard HTTPS port)
    echo   2. Install its local SSL certificate in Windows Trust Store
    echo.
    echo   Please right-click on this file (start-proxy.bat)
    echo   and select "Run as administrator".
    echo ============================================================
    echo.
    pause
    exit /b 1
)

:: Check if caddy.exe exists, if not download it
if not exist caddy.exe (
    echo caddy.exe not found. Downloading Caddy v2...
    powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'caddy.exe'"
    if not exist caddy.exe (
        echo Failed to download caddy.exe. Please check your internet connection.
        pause
        exit /b 1
    )
    echo Caddy successfully downloaded!
)

echo.
echo ============================================================
echo   TGG HTTPS Reverse Proxy (Caddy) Started
echo   --------------------------------------------------------
echo   URL:           https://tgg.test
echo   Forwarding to: http://localhost:8080
echo ============================================================
echo.

caddy run --config Caddyfile
pause
