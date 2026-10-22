"""Utilidades compartidas por las pruebas.

Las pruebas no necesitan el modelo entrenado: usan un clasificador falso que decide
por el color dominante de la foto (rojo -> primera, verde -> segunda, azul -> tercera,
amarillo -> no_es_coca). Así se prueba toda la API sin GPU ni internet.
"""

from __future__ import annotations

import io
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.config import Settings
from app.main import crear_app
from app.servicios.analisis import PrediccionImagen
from app.servicios.clasificador import DetectorOOD
from app.servicios.reconocimiento import ServicioReconocimiento

ROJO, VERDE, AZUL, AMARILLO, GRIS = (220, 20, 20), (20, 200, 30), (20, 30, 210), (220, 200, 20), (128, 128, 128)
MAPA_FALSO = "data:image/jpeg;base64,/9j/4AAQ"


def jpeg(color=ROJO, tamano=(320, 240), formato="JPEG") -> bytes:
    """Foto sintética con un poco de textura (para que no sea un color plano)."""
    rng = np.random.default_rng(sum(color))
    base = np.zeros((tamano[1], tamano[0], 3), dtype=np.float32) + np.array(color, dtype=np.float32)
    base += rng.normal(0, 8, base.shape)
    imagen = Image.fromarray(np.clip(base, 0, 255).astype(np.uint8))
    buffer = io.BytesIO()
    imagen.save(buffer, format=formato)
    return buffer.getvalue()


class ClasificadorFalso:
    """Imita a ClasificadorCoca. Las fotos grises se marcan como ajenas al dataset."""

    def __init__(self, mapas: bool = True):
        self.listo = False
        self.error = "El modelo aún no se cargó."
        self.nombres = {0: "primera", 1: "segunda", 2: "tercera", 3: "no_es_coca"}
        self.imgsz = 224
        self.tta = True
        self.device = "cpu"
        self.arquitectura = "falso"
        self.ruta_modelo = Path("falso.pt")
        self.ood = DetectorOOD(banco=np.eye(3, dtype=np.float32), k=1, umbral=0.2)
        self.mapas_activos = mapas
        self.indices_mapas: list[int] = []

    def cargar(self, meta: dict) -> None:
        self.listo, self.error = True, None

    def predecir(self, imagenes: list[Image.Image]) -> list[PrediccionImagen]:
        preds = []
        for imagen in imagenes:
            r, g, b = np.asarray(imagen.convert("RGB"), dtype=np.float64).reshape(-1, 3).mean(axis=0)
            gris = float(max(r, g, b) - min(r, g, b)) < 15
            logits = np.array([r - (g + b) / 2, g - (r + b) / 2, b - (r + g) / 2, min(r, g) - b]) / 20.0
            probs = np.exp(logits - logits.max())
            preds.append(
                PrediccionImagen(
                    probs=probs / probs.sum(),
                    distancia_ood=0.5 if gris else 0.02,
                    fuera_de_distribucion=gris,
                )
            )
        return preds

    def mapas_atencion(self, imagenes: list[Image.Image], indice: int) -> list[str | None]:
        self.indices_mapas.append(indice)
        return [MAPA_FALSO if self.mapas_activos else None for _ in imagenes]


@pytest.fixture
def ajustes(tmp_path: Path):
    def fabricar(**cambios) -> Settings:
        valores = {
            "modelo_path": tmp_path / "falso.pt",
            "modelo_meta_path": tmp_path / "model_meta.json",
            "ood_path": tmp_path / "ood.npz",
            "clases_path": tmp_path / "clases.json",
            "capturas_dir": tmp_path / "capturas",
            "api_key": "",
            "cors_origins": "",
            "clases_rechazo": "",
        }
        valores.update(cambios)
        return Settings(_env_file=None, **valores)

    return fabricar


@pytest.fixture
def crear_cliente(ajustes):
    """Devuelve una función que arma la API con el clasificador falso."""

    def fabricar(clasificador=None, **cambios) -> TestClient:
        settings = ajustes(**cambios)
        servicio = ServicioReconocimiento(settings, clasificador=clasificador or ClasificadorFalso())
        servicio.iniciar()
        return TestClient(crear_app(settings, servicio))

    return fabricar
