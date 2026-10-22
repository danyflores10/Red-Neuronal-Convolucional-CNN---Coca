"""Explicación en lenguaje natural del resultado, generada por la propia API.

No usa ningún modelo de lenguaje externo: redacta el informe a partir de lo que
midió la red neuronal (probabilidades, coincidencia entre fotos, fotos descartadas)
y de la calidad de las fotos, con los textos de cada clase de `config/clases.json`.
"""

from __future__ import annotations

import numpy as np

from .analisis import CONFIRMADO, NO_CONCLUYENTE, PROBABLE, RECHAZADO, Veredicto
from .catalogo import Catalogo
from .imagenes import CalidadImagen

CONCLUSION = {
    CONFIRMADO: "Con esta evidencia, el resultado es confiable.",
    PROBABLE: "Es un resultado probable: conviene repetir las fotos para confirmarlo.",
    NO_CONCLUYENTE: "No es suficiente para dar un resultado seguro; repite las fotos con buena luz y la hoja centrada.",
}


def _nivel(valor: float, bueno: float, aceptable: float, palabras: tuple[str, str, str]) -> str:
    return palabras[0] if valor >= bueno else palabras[1] if valor >= aceptable else palabras[2]


def redactar_explicacion(
    v: Veredicto,
    catalogo: Catalogo,
    calidades: list[CalidadImagen],
    rechazo: frozenset[int] = frozenset(),
) -> str:
    n = len(v.votos)
    fotos = "la foto" if n == 1 else f"las {n} fotos"

    if v.estado == RECHAZADO:
        return (
            f"Analicé {fotos} con la red neuronal y no reconozco una hoja de coca: la probabilidad de que no lo sea "
            f"es de {v.confianza * 100:.1f} %. Coloca una sola hoja dentro del marco, sobre un fondo claro, y "
            "vuelve a tomar las fotos."
        )

    info = catalogo[v.indice]
    hojas = [i for i in np.argsort(-v.probs, kind="stable") if int(i) not in rechazo]
    partes = [f"Analicé {fotos} con la red neuronal convolucional."]

    principal = f"El tipo de hoja más probable es «{info.nombre}», con {v.confianza * 100:.1f} % de confianza"
    if len(hojas) > 1:
        segunda = int(hojas[1])
        p2 = float(v.probs[segunda]) * 100
        margen = v.confianza * 100 - p2
        detalle = " (diferencia amplia)" if margen >= 40 else " (diferencia estrecha)" if margen < 15 else ""
        principal += f"; la segunda opción, «{catalogo[segunda].nombre}», obtuvo {p2:.1f} %{detalle}"
    partes.append(principal + ".")

    if n > 1:
        partes.append(f"{v.fotos_coincidentes} de {len(v.usadas)} fotos analizadas señalan el mismo tipo.")
    if v.fuera:
        k = len(v.fuera)
        partes.append(
            f"{'Se descartó 1 foto' if k == 1 else f'Se descartaron {k} fotos'} porque no se "
            f"{'parece' if k == 1 else 'parecen'} a las del entrenamiento."
        )

    if calidades:
        iluminacion = float(np.mean([c.iluminacion for c in calidades]))
        nitidez = float(np.mean([c.nitidez for c in calidades]))
        partes.append(
            f"La iluminación fue {_nivel(iluminacion, 0.8, 0.5, ('buena', 'aceptable', 'deficiente'))} "
            f"y la nitidez {_nivel(nitidez, 0.25, 0.05, ('buena', 'aceptable', 'baja'))}."
        )

    if info.descripcion:
        partes.append(f"Rasgos de este tipo: {info.descripcion.rstrip('.')}.")
    partes.append(CONCLUSION.get(v.estado, ""))
    return " ".join(p for p in partes if p)
