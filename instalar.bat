@echo off
setlocal
title Instalacion - API de Reconocimiento de Hoja de Coca
cd /d "%~dp0"

rem El entorno virtual se crea FUERA de OneDrive para no sincronizar ~1.5 GB de librerias
set "VENV=%LOCALAPPDATA%\ApiCoca\venv"

echo ============================================================
echo   API de Reconocimiento de Hoja de Coca - ADEPCOCA
echo   Instalacion de dependencias
echo ============================================================
echo.

set "PY="
where py >nul 2>nul && set "PY=py -3"
if not defined PY where python >nul 2>nul && set "PY=python"
if not defined PY (
    echo [ERROR] No se encontro Python. Instala Python 3.10 o superior desde https://www.python.org/downloads/
    echo         y marca la opcion "Add python.exe to PATH".
    pause
    exit /b 1
)

%PY% -c "import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)"
if errorlevel 1 (
    echo [ERROR] Se necesita Python 3.10 o superior.
    %PY% --version
    pause
    exit /b 1
)

if not exist "%VENV%\Scripts\python.exe" (
    echo Creando el entorno virtual en %VENV% ...
    %PY% -m venv "%VENV%"
    if errorlevel 1 (
        echo [ERROR] No se pudo crear el entorno virtual.
        pause
        exit /b 1
    )
)

echo Instalando librerias: FastAPI, PyTorch y Ultralytics YOLO ...
echo La primera vez descarga cerca de 500 MB y puede tardar varios minutos.
"%VENV%\Scripts\python.exe" -m pip install --upgrade pip
"%VENV%\Scripts\python.exe" -m pip install -r requirements-dev.txt
if errorlevel 1 (
    echo [ERROR] Fallo la instalacion. Revisa tu conexion a internet y vuelve a ejecutar instalar.bat
    pause
    exit /b 1
)

if not exist ".env" (
    copy ".env.example" ".env" >nul
    echo Se creo el archivo .env con la configuracion por defecto.
)

echo.
echo ============================================================
echo   Instalacion completa.
echo   1. Entrena el modelo con notebooks\Entrenamiento_CNN_Hoja_Coca_YOLO26.ipynb en Google Colab
echo   2. Descomprime modelo_coca_api.zip dentro de esta carpeta
echo   3. Ejecuta iniciar_api.bat
echo ============================================================
pause
