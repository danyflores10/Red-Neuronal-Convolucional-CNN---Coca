"""Lectura segura de imágenes, catálogo de clases y métricas de calidad."""

import io
import json

import numpy as np
import pytest
from PIL import Image, ImageFilter

from app.servicios.catalogo import Catalogo, nombre_legible, normalizar_clave
from app.servicios.imagenes import LADO_MAXIMO, ImagenInvalida, decodificar_imagen, medir_calidad
from tests.conftest import jpeg


def _guardar(imagen: Image.Image, formato: str, **opciones) -> bytes:
    buffer = io.BytesIO()
    imagen.save(buffer, format=formato, **opciones)
    return buffer.getvalue()


@pytest.mark.parametrize("formato", ["JPEG", "PNG", "WEBP", "BMP"])
def test_formatos_permitidos(formato):
    imagen = decodificar_imagen(jpeg(formato=formato), f"foto.{formato.lower()}")
    assert imagen.mode == "RGB" and imagen.size == (320, 240)


def test_png_transparente_se_rellena_de_blanco():
    rgba = Image.new("RGBA", (100, 100), (0, 0, 0, 0))
    imagen = decodificar_imagen(_guardar(rgba, "PNG"), "hoja.png")
    assert imagen.getpixel((50, 50)) == (255, 255, 255)


def test_aplica_la_rotacion_exif_de_los_celulares():
    exif = Image.Exif()
    exif[0x0112] = 6  # rotar 90° (foto vertical de celular)
    datos = _guardar(Image.new("RGB", (200, 100), (10, 120, 10)), "JPEG", exif=exif.tobytes())
    assert decodificar_imagen(datos, "celular.jpg").size == (100, 200)


def test_reduce_las_fotos_muy_grandes():
    datos = _guardar(Image.new("RGB", (5000, 3000), (10, 120, 10)), "JPEG")
    assert max(decodificar_imagen(datos, "grande.jpg").size) <= LADO_MAXIMO


@pytest.mark.parametrize(
    ("datos", "texto"),
    [
        (b"", "vacío"),
        (b"esto no es una imagen", "no es una imagen"),
        (_guardar(Image.new("RGB", (30, 30)), "PNG"), "pequeña"),
        (_guardar(Image.new("RGB", (100, 100)), "GIF"), "formato no permitido"),
    ],
)
def test_rechaza_archivos_invalidos(datos, texto):
    with pytest.raises(ImagenInvalida, match=texto):
        decodificar_imagen(datos, "archivo")


def test_detecta_foto_oscura_y_borrosa():
    rng = np.random.default_rng(1)
    textura = Image.fromarray(rng.integers(0, 255, (300, 400, 3), dtype=np.uint8))
    nitida = medir_calidad(textura)
    borrosa = medir_calidad(textura.filter(ImageFilter.GaussianBlur(8)))
    oscura = medir_calidad(Image.new("RGB", (300, 300), (10, 10, 10)))

    assert nitida.nitidez > borrosa.nitidez
    assert any("borrosa" in a for a in borrosa.advertencias)
    assert any("oscura" in a for a in oscura.advertencias)
    assert not nitida.advertencias


def test_normalizar_claves():
    assert normalizar_clave("Quispiñu Especial") == "quispinu_especial"
    assert normalizar_clave("hoja-de_PRIMERA ") == "hoja_de_primera"
    assert nombre_legible("hoja_de_primera") == "Hoja de primera"


def test_catalogo_crea_plantilla_y_usa_textos(tmp_path):
    ruta = tmp_path / "clases.json"
    nombres = {0: "primera", 1: "Quispiñu"}

    catalogo = Catalogo.desde_archivo(ruta, nombres)
    plantilla = json.loads(ruta.read_text(encoding="utf-8"))
    assert set(plantilla["clases"]) == {"primera", "Quispiñu"}
    assert catalogo[1].nombre == "Quispiñu"

    plantilla["clases"]["quispinu"] = {"nombre": "Quispiñu (especial)", "descripcion": "Hoja pequeña.", "recomendacion": "X"}
    ruta.write_text(json.dumps(plantilla, ensure_ascii=False), encoding="utf-8")
    catalogo = Catalogo.desde_archivo(ruta, nombres)
    assert catalogo[1].nombre == "Quispiñu (especial)" and catalogo[1].descripcion == "Hoja pequeña."
    assert catalogo.indice_por_clave("QUISPIÑU") == 1
