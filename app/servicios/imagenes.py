"""Lectura segura de las imágenes recibidas y métricas básicas de calidad."""

from __future__ import annotations

import base64
import io
import warnings
from dataclasses import dataclass, field

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

FORMATOS_PERMITIDOS = {"JPEG", "MPO", "PNG", "WEBP", "BMP"}
LADO_MAXIMO = 2048  # se reduce al cargar para ahorrar memoria (el modelo trabaja con ~320 px)
LADO_MINIMO = 64

# Protección contra imágenes "bomba" (archivos pequeños que se expanden a gigas en memoria)
Image.MAX_IMAGE_PIXELS = 60_000_000

try:  # fotos HEIC de iPhone
    from pi_heif import register_heif_opener

    register_heif_opener()
    FORMATOS_PERMITIDOS.add("HEIF")
except ImportError:
    pass


class ImagenInvalida(ValueError):
    """La imagen recibida no se puede usar para el análisis."""


@dataclass
class CalidadImagen:
    ancho: int
    alto: int
    brillo: float  # 0-1, luminancia media
    contraste: float  # 0-1, dispersión de la luminancia
    nitidez: float  # 0-1, varianza del laplaciano normalizada
    advertencias: list[str] = field(default_factory=list)

    @property
    def iluminacion(self) -> float:
        """Puntaje 0-1: 1 cuando el brillo está en un rango cómodo (0.40-0.70)."""
        exceso = max(0.0, abs(self.brillo - 0.55) - 0.15)
        return float(np.clip(1.0 - exceso / 0.40, 0.0, 1.0))


def decodificar_imagen(datos: bytes, nombre: str = "imagen") -> Image.Image:
    """Convierte los bytes recibidos en una imagen RGB con la orientación corregida."""
    if not datos:
        raise ImagenInvalida(f"El archivo '{nombre}' está vacío.")
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(datos)) as original:
                if original.format not in FORMATOS_PERMITIDOS:
                    raise ImagenInvalida(
                        f"'{nombre}' tiene un formato no permitido ({original.format}). Usa JPG, PNG o WEBP."
                    )
                if original.format in ("JPEG", "MPO"):
                    # Decodifica directamente a menor escala: más rápido y con menos memoria
                    original.draft("RGB", (LADO_MAXIMO, LADO_MAXIMO))
                # Las fotos de celular guardan la rotación en EXIF; se aplica aquí
                imagen = _a_rgb(ImageOps.exif_transpose(original))
    except ImagenInvalida:
        raise
    except (Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise ImagenInvalida(f"'{nombre}' tiene demasiados megapíxeles para analizarse.") from exc
    except (UnidentifiedImageError, OSError, SyntaxError, ValueError, ImportError) as exc:
        raise ImagenInvalida(f"'{nombre}' no es una imagen válida o está dañada.") from exc

    if min(imagen.size) < LADO_MINIMO:
        raise ImagenInvalida(
            f"'{nombre}' es demasiado pequeña ({imagen.width}x{imagen.height} px). "
            f"El mínimo es {LADO_MINIMO} px por lado."
        )
    if max(imagen.size) > LADO_MAXIMO:
        imagen.thumbnail((LADO_MAXIMO, LADO_MAXIMO), Image.Resampling.BILINEAR)
    return imagen


def _a_rgb(imagen: Image.Image) -> Image.Image:
    """RGB puro. Las zonas transparentes se rellenan de blanco (fondo claro, como pide la guía de captura)."""
    if imagen.mode in ("RGBA", "LA") or (imagen.mode == "P" and "transparency" in imagen.info):
        rgba = imagen.convert("RGBA")
        fondo = Image.new("RGB", rgba.size, (255, 255, 255))
        fondo.paste(rgba, mask=rgba.getchannel("A"))
        return fondo
    return imagen.convert("RGB")


def medir_calidad(imagen: Image.Image) -> CalidadImagen:
    """Brillo, contraste y nitidez de la foto, con advertencias en lenguaje sencillo."""
    gris = imagen.convert("L")
    gris.thumbnail((512, 512), Image.Resampling.BILINEAR)
    g = np.asarray(gris, dtype=np.float32)

    brillo = float(g.mean() / 255.0)
    contraste = float(min(1.0, g.std() / 127.5))
    laplaciano = g[:-2, 1:-1] + g[2:, 1:-1] + g[1:-1, :-2] + g[1:-1, 2:] - 4.0 * g[1:-1, 1:-1]
    nitidez = float(min(1.0, laplaciano.var() / 400.0))

    advertencias = []
    if brillo < 0.18:
        advertencias.append("Foto muy oscura: busca más luz.")
    elif brillo > 0.90:
        advertencias.append("Foto sobreexpuesta: evita el sol directo o el flash.")
    if contraste < 0.05:
        advertencias.append("Foto sin contraste: revisa que la cámara no esté tapada.")
    if nitidez < 0.02:
        advertencias.append("Foto borrosa: mantén la cámara quieta y enfoca la hoja.")

    return CalidadImagen(
        ancho=imagen.width,
        alto=imagen.height,
        brillo=round(brillo, 4),
        contraste=round(contraste, 4),
        nitidez=round(nitidez, 4),
        advertencias=advertencias,
    )


def a_jpeg_base64(imagen: Image.Image, lado_maximo: int = 1024, calidad: int = 85) -> str:
    """JPEG reducido en base64 (para enviarlo a la verificación con IA)."""
    copia = imagen.copy()
    copia.thumbnail((lado_maximo, lado_maximo), Image.Resampling.LANCZOS)
    buffer = io.BytesIO()
    copia.save(buffer, format="JPEG", quality=calidad, optimize=True)
    return base64.standard_b64encode(buffer.getvalue()).decode("ascii")
