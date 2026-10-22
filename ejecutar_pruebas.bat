@echo off
setlocal
cd /d "%~dp0"
set "VENV=%LOCALAPPDATA%\ApiCoca\venv"
if not exist "%VENV%\Scripts\python.exe" (
    echo [ERROR] Primero ejecuta instalar.bat
    pause
    exit /b 1
)
set PYTHONUTF8=1
"%VENV%\Scripts\python.exe" -m pytest -v
pause
