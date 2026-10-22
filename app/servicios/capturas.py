"""Guarda las fotos analizadas para revisarlas y re-entrenar el modelo (opcional, GUARDAR_CAPTURAS=true).

Cada análisis queda en data/capturas/AAAA-MM-DD/HHMMSS_<clase>_<estado>_<id>/ con las
fotos y el resultado.json. Revisa las etiquetas, súbelas a Roboflow y vuelve a entrenar:
es la forma más efectiva de mejorar la precisión con fotos reales del sistema.
"""

from __future__ import annotations

import logging
import re
from datetime import datetime
from pathlib import Path
from uuid import uuid4

from PIL import Image

from ..schemas import RespuestaAnalisis

log = logging.getLogger("api_coca")


def guardar_capturas(directorio: Path, imagenes: list[Image.Image], respuesta: RespuestaAnalisis) -> None:
    try:
        ahora = datetime.now()
        clase = re.sub(r"[^A-Za-z0-9_-]+", "_", respuesta.clave)[:40]
        carpeta = directorio / f"{ahora:%Y-%m-%d}" / f"{ahora:%H%M%S}_{clase}_{respuesta.estado}_{uuid4().hex[:6]}"
        carpeta.mkdir(parents=True, exist_ok=True)
        for numero, imagen in enumerate(imagenes, start=1):
            imagen.save(carpeta / f"foto_{numero}.jpg", format="JPEG", quality=92)
        (carpeta / "resultado.json").write_text(respuesta.model_dump_json(indent=2), encoding="utf-8")
    except Exception as exc:  # guardar es secundario: nunca debe romper el análisis
        log.warning("No se pudieron guardar las capturas: %s", exc)
