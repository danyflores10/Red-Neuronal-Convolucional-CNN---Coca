@echo off
setlocal
title API de Reconocimiento de Hoja de Coca - ADEPCOCA
cd /d "%~dp0"
set "VENV=%LOCALAPPDATA%\ApiCoca\venv"

if not exist "%VENV%\Scripts\python.exe" (
    echo [ERROR] Primero ejecuta instalar.bat
    pause
    exit /b 1
)
if not exist ".env" copy ".env.example" ".env" >nul

if not exist "weights\best.pt" (
    echo [AVISO] Falta weights\best.pt. Entrena el modelo con el notebook de Colab
    echo         y descomprime modelo_coca_api.zip en esta carpeta.
    echo         La API arranca igual, pero respondera "modelo no cargado" hasta entonces.
    echo.
)

set PYTHONUTF8=1
"%VENV%\Scripts\python.exe" run.py
pause
