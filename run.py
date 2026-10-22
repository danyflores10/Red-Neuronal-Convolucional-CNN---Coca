"""Inicia la API: `python run.py` (lee HOST, PORT y el resto de la configuración del archivo .env)."""

import json
import socket
import sys
import urllib.error
import urllib.request

import uvicorn

from app.config import get_settings


def quien_usa_el_puerto(host: str, port: int) -> str | None:
    """None si el puerto está libre; "api" si ya lo usa esta misma API; "otro" si es otro programa."""
    destino = "127.0.0.1" if host in ("0.0.0.0", "::", "") else host
    try:
        with socket.create_connection((destino, port), timeout=1):
            pass
    except OSError:
        return None
    try:
        with urllib.request.urlopen(f"http://{destino}:{port}/api/health", timeout=3) as respuesta:
            datos = json.load(respuesta)
    except urllib.error.HTTPError as error:  # la API responde 503 mientras no tenga el modelo cargado
        try:
            datos = json.load(error)
        except ValueError:
            return "otro"
    except (OSError, ValueError):
        return "otro"
    return "api" if isinstance(datos, dict) and ("modelo_cargado" in datos or "codigo" in datos) else "otro"


if __name__ == "__main__":
    settings = get_settings()
    url = f"http://{settings.host}:{settings.port}"

    ocupado = quien_usa_el_puerto(settings.host, settings.port)
    if ocupado == "api":
        print(f"\n  La API ya está encendida en {url}. No hace falta abrirla otra vez: puedes cerrar esta ventana.\n")
        sys.exit(0)
    if ocupado == "otro":
        print(f"\n  [ERROR] Otro programa ya usa el puerto {settings.port}. Ciérralo, o cambia PORT en el archivo .env"
              f"\n          de la API (y COCA_API_URL en el .env de Laravel) por un puerto libre.\n")
        sys.exit(1)

    from app.main import app  # se importa después de la verificación: cargar la red neuronal tarda unos segundos

    print(f"\n  API de Reconocimiento de Hoja de Coca -> {url}")
    print(f"  Documentación interactiva            -> {url}/docs\n")
    uvicorn.run(app, host=settings.host, port=settings.port, log_level=settings.log_level.lower())
