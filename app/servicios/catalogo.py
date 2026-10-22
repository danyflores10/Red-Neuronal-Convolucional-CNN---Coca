"""Catálogo de clases: nombre visible, descripción y recomendación de cada tipo de hoja.

Las clases las define el modelo entrenado (carpetas del dataset de Roboflow). Este
módulo solo agrega los textos para la interfaz, que se editan en `config/clases.json`.
Si el archivo no existe, se genera con las clases del modelo para completarlo.
"""

from __future__ import annotations

import json
import logging
import re
import unicodedata
from dataclasses import dataclass
from pathlib import Path

log = logging.getLogger("api_coca")

INSTRUCCIONES = (
    "Completa el nombre visible, la descripción y la recomendación de cada clase. "
    "La clave de cada entrada es el nombre de la clase tal como sale del modelo "
    "(la carpeta del dataset en Roboflow). No cambies las claves."
)

# Nombres (normalizados) de la clase con la que la red aprende a decir "esto no es una hoja de coca"
NOMBRES_RECHAZO = frozenset({
    "no_es_coca", "no_coca", "nococa", "no_es_hoja", "otro", "otros", "otra", "otras",
    "fondo", "background", "ninguno", "ninguna", "negativo", "negativos",
})
NOMBRE_VISIBLE_RECHAZO = "No es hoja de coca"


@dataclass(frozen=True)
class InfoClase:
    indice: int
    clave: str
    nombre: str
    descripcion: str
    recomendacion: str


def normalizar_clave(texto: str) -> str:
    """'Quispiñu Especial' -> 'quispinu_especial' (para emparejar sin importar tildes ni espacios)."""
    sin_tildes = unicodedata.normalize("NFKD", str(texto)).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"[^a-z0-9]+", "_", sin_tildes.lower()).strip("_")


def es_clase_rechazo(clave: str, nombres: frozenset[str] | set[str] = NOMBRES_RECHAZO) -> bool:
    return normalizar_clave(clave) in nombres


def nombre_legible(clave: str) -> str:
    """'hoja_de_primera' -> 'Hoja de primera'."""
    if es_clase_rechazo(clave):
        return NOMBRE_VISIBLE_RECHAZO
    texto = re.sub(r"[_\-]+", " ", str(clave)).strip()
    return texto[:1].upper() + texto[1:] if texto else str(clave)


class Catalogo:
    def __init__(self, clases: list[InfoClase]):
        self.clases = clases
        self._por_indice = {c.indice: c for c in clases}

    @classmethod
    def desde_archivo(cls, ruta: Path, nombres_modelo: dict[int, str]) -> "Catalogo":
        textos = _leer_textos(ruta)
        if textos is None:
            _crear_plantilla(ruta, nombres_modelo)
            textos = {}

        por_clave = {normalizar_clave(k): v for k, v in textos.items() if isinstance(v, dict)}
        clases = []
        for indice, clave in sorted(nombres_modelo.items()):
            datos = por_clave.get(normalizar_clave(clave), {})
            clases.append(
                InfoClase(
                    indice=int(indice),
                    clave=str(clave),
                    nombre=str(datos.get("nombre") or nombre_legible(clave)).strip(),
                    descripcion=str(datos.get("descripcion") or "").strip(),
                    recomendacion=str(datos.get("recomendacion") or "").strip(),
                )
            )

        sin_textos = [c.clave for c in clases if not c.descripcion]
        if textos and sin_textos:
            log.warning("Clases sin descripción en %s: %s", ruta.name, ", ".join(sin_textos))
        return cls(clases)

    def __getitem__(self, indice: int) -> InfoClase:
        return self._por_indice[indice]

    def __len__(self) -> int:
        return len(self.clases)

    def indice_por_clave(self, clave: str) -> int | None:
        buscada = normalizar_clave(clave)
        for c in self.clases:
            if normalizar_clave(c.clave) == buscada:
                return c.indice
        return None

    def indices_rechazo(self, nombres: list[str] | None = None) -> frozenset[int]:
        """Clases de "no es hoja de coca" del modelo (por nombre). `nombres` reemplaza a los habituales."""
        buscados = frozenset(normalizar_clave(n) for n in nombres) if nombres else NOMBRES_RECHAZO
        return frozenset(c.indice for c in self.clases if es_clase_rechazo(c.clave, buscados))


def _leer_textos(ruta: Path) -> dict | None:
    if not ruta.exists():
        return None
    try:
        contenido = json.loads(ruta.read_text(encoding="utf-8-sig"))
    except (OSError, json.JSONDecodeError) as exc:
        log.error("No se pudo leer %s (%s). Se usarán nombres automáticos.", ruta, exc)
        return {}
    clases = contenido.get("clases", contenido) if isinstance(contenido, dict) else {}
    return clases if isinstance(clases, dict) else {}


def _crear_plantilla(ruta: Path, nombres_modelo: dict[int, str]) -> None:
    plantilla = {
        "_instrucciones": INSTRUCCIONES,
        "clases": {
            str(clave): {"nombre": nombre_legible(clave), "descripcion": "", "recomendacion": ""}
            for _, clave in sorted(nombres_modelo.items())
        },
    }
    try:
        ruta.parent.mkdir(parents=True, exist_ok=True)
        ruta.write_text(json.dumps(plantilla, ensure_ascii=False, indent=2), encoding="utf-8")
        log.info("Se creó %s con las %d clases del modelo. Complétalo con descripciones.", ruta, len(nombres_modelo))
    except OSError as exc:
        log.warning("No se pudo crear la plantilla %s: %s", ruta, exc)
