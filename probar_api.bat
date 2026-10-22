@echo off
setlocal
cd /d "%~dp0"
set "VENV=%LOCALAPPDATA%\ApiCoca\venv"
if not exist "%VENV%\Scripts\python.exe" (
    echo [ERROR] Primero ejecuta instalar.bat
    pause
    exit /b 1
)
rem Uso: probar_api.bat                      -> estado y clases
rem      probar_api.bat foto1.jpg foto2.jpg  -> ademas analiza esas fotos
set PYTHONUTF8=1
"%VENV%\Scripts\python.exe" scripts\probar_api.py %*
pause
