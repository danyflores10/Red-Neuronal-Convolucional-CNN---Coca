"""Prueba con el modelo real de weights/ (se omite si todavía no entrenaste el modelo en Colab)."""

import io

import numpy as np
import pytest
from PIL import Image

from app.config import Settings
from app.servicios.reconocimiento import ServicioReconocimiento

AJUSTES = Settings()  # lee el .env, igual que la API
pytestmark = pytest.mark.skipif(
    not AJUSTES.modelo_path.exists(), reason="Falta weights/best.pt: entrena el modelo con el notebook de Colab"
)


def jpeg_hoja(semilla: int) -> bytes:
    """Imagen sintética verde con textura (solo para probar el funcionamiento, no la precisión)."""
    rng = np.random.default_rng(semilla)
    base = np.zeros((480, 640, 3), dtype=np.float32) + np.array([60, 140, 50], dtype=np.float32)
    imagen = Image.fromarray(np.clip(base + rng.normal(0, 20, base.shape), 0, 255).astype(np.uint8))
    buffer = io.BytesIO()
    imagen.save(buffer, format="JPEG")
    return buffer.getvalue()


@pytest.fixture(scope="module")
def servicio(tmp_path_factory):
    ajustes = AJUSTES.model_copy(update={"clases_path": tmp_path_factory.mktemp("config") / "clases.json"})
    servicio = ServicioReconocimiento(ajustes)
    servicio.iniciar()
    assert servicio.listo, servicio.clasificador.error
    return servicio


def test_el_modelo_real_analiza_fotos(servicio):
    respuesta = servicio.analizar([(f"foto{i}.jpg", jpeg_hoja(i)) for i in range(3)])
    assert respuesta.status == "success" and len(respuesta.por_imagen) == 3
    assert abs(sum(respuesta.probabilidades.values()) - 1.0) < 0.02
    assert respuesta.explicacion.startswith("Analicé")
    assert respuesta.modelo.imgsz == servicio.clasificador.imgsz


def test_mapas_de_atencion_del_modelo_real(servicio):
    if not servicio.clasificador.mapas_activos:
        pytest.skip("MAPAS_ATENCION=false")
    respuesta = servicio.analizar([("foto.jpg", jpeg_hoja(7))])
    assert respuesta.por_imagen[0].mapa_atencion.startswith("data:image/jpeg;base64,")
