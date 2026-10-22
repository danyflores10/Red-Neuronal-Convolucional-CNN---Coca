"""Prueba rápida de la API en ejecución.

    python scripts/probar_api.py                       -> estado y clases
    python scripts/probar_api.py foto1.jpg foto2.jpg   -> además analiza esas fotos (misma hoja)
"""

import base64
import sys
from pathlib import Path

import requests

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.config import get_settings  # noqa: E402


def main() -> int:
    s = get_settings()
    base = f"http://{'127.0.0.1' if s.host in ('0.0.0.0', '::') else s.host}:{s.port}"
    cabeceras = {"X-API-Key": s.api_key} if s.api_key else {}

    try:
        salud = requests.get(f"{base}/api/health", timeout=5)
    except requests.ConnectionError:
        print(f"No hay conexión con {base}. ¿Ejecutaste iniciar_api.bat?")
        return 1
    print(f"Salud ({salud.status_code}):", salud.json())

    estado = requests.get(f"{base}/api/status", headers=cabeceras, timeout=10).json()
    if estado.get("status") != "success":
        print("El modelo no está cargado:", estado.get("message"))
        return 1
    modelo = estado["modelo"]
    print(f"Modelo: {modelo['arquitectura']} · {modelo['num_clases']} clases · imgsz {modelo['imgsz']} · TTA {modelo['tta']}")
    print("Clases:", ", ".join(modelo["clases"]))
    print("Clase «no es hoja de coca»:", ", ".join(modelo["clase_rechazo"]) or "no tiene")
    print("Mapas de atención:", "sí" if modelo["mapas_atencion"] else "no", "· Umbrales:", estado["umbrales"])

    fotos = [Path(p) for p in sys.argv[1:]]
    if not fotos:
        print("\nPara analizar fotos: probar_api.bat foto1.jpg foto2.jpg ...")
        return 0

    archivos = [("imagenes", (f.name, f.read_bytes(), "image/jpeg")) for f in fotos]
    respuesta = requests.post(f"{base}/api/analizar", files=archivos, headers=cabeceras, timeout=120)
    datos = respuesta.json()
    if respuesta.status_code != 200:
        print(f"\nError {respuesta.status_code}: {datos.get('message')}")
        return 1

    print(f"\nResultado: {datos['estado'].upper()} · {datos['clase']} · confianza {datos['confianza'] * 100:.1f}%")
    print(datos["mensaje"])
    print("\nExplicación de la red neuronal:\n  " + datos["explicacion"])
    carpeta = Path(__file__).resolve().parent.parent / "resultados_prueba"
    for foto in datos["por_imagen"]:
        extra = " (ajena al dataset)" if foto["fuera_de_distribucion"] else ""
        print(f"  Foto {foto['indice']} ({foto['archivo']}): {foto['clase']} {foto['confianza'] * 100:.1f}%{extra}")
        if foto.get("mapa_atencion"):
            carpeta.mkdir(exist_ok=True)
            destino = carpeta / f"atencion_foto_{foto['indice']}.jpg"
            destino.write_bytes(base64.b64decode(foto["mapa_atencion"].split(",", 1)[1]))
    for aviso in datos["advertencias"]:
        print("  Aviso:", aviso)
    if carpeta.exists():
        print(f"Mapas de atención guardados en: {carpeta}")
    print(f"Tiempo: {datos['tiempo_proceso']} s")
    return 0


if __name__ == "__main__":
    sys.exit(main())
