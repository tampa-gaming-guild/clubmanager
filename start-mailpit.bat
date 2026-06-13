@echo off
title Mailpit Mock Email Server
echo ============================================================
echo   Mailpit Mock Email Server (SMTP: 1025, Web UI: 8025)
echo   --------------------------------------------------------
echo   SMTP Host:     127.0.0.1:1025
echo   Web UI URL:    http://localhost:8025
echo ============================================================
echo.
echo Press Ctrl+C to stop the server.
echo.
"%~dp0mailpit\mailpit.exe"
