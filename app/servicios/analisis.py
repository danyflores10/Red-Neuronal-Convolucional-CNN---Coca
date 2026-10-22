"""Combina las predicciones de varias fotos y decide el veredicto final.

Lógica pura (sin el modelo) para poder probarla de forma aislada:

1. Se promedian las probabilidades de las fotos (votación suave). Las fotos que el
   detector de "fuera de distribución" marca como ajenas al dataset no entran al
   promedio, salvo que todas lo sean.
2. Primero se decide si es hoja de coca: si la clase de rechazo de la red
   ("no_es_coca") reúne más de la mitad de la probabilidad, el resultado es
   "rechazado".
3. Si es hoja de coca, el tipo es la clase de hoja más probable y el estado depende
   de la confianza y de cuántas fotos coinciden: confirmado -> probable -> no_concluyente.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

CONFIRMADO = "confirmado"
PROBABLE = "probable"
NO_CONCLUYENTE = "no_concluyente"
RECHAZADO = "rechazado"


@dataclass(frozen=True)
class Umbrales:
    confianza_alta: float = 0.80
    confianza_minima: float = 0.55
    consistencia: float = 0.60
    fraccion_fuera_max: float = 0.50  # más de la mitad de fotos ajenas al dataset => no concluyente
    rechazo: float = 0.50  # probabilidad de "no es hoja de coca" a partir de la cual se rechaza


@dataclass
class PrediccionImagen:
    probs: np.ndarray  # probabilidad por clase (suma 1)
    distancia_ood: float | None = None  # distancia kNN al dataset de entrenamiento
    fuera_de_distribucion: bool = False


def decidir(probs: np.ndarray, rechazo: frozenset[int] = frozenset(), umbral_rechazo: float = 0.50) -> int:
    """Clase elegida: la de rechazo si supera el umbral; si no, la hoja de coca más probable."""
    if rechazo:
        indices_rechazo = sorted(rechazo)
        if float(probs[indices_rechazo].sum()) > umbral_rechazo:
            return indices_rechazo[int(np.argmax(probs[indices_rechazo]))]
        hojas = np.ones(len(probs), dtype=bool)
        hojas[indices_rechazo] = False
        if hojas.any():
            return int(np.flatnonzero(hojas)[np.argmax(probs[hojas])])
    return int(np.argmax(probs))


@dataclass(frozen=True)
class Veredicto:
    probs: np.ndarray
    indice: int
    confianza: float
    consistencia: float
    votos: tuple[int, ...]  # clase elegida en cada foto
    usadas: tuple[int, ...]  # fotos que entraron al promedio
    fuera: tuple[int, ...]  # fotos fuera de distribución
    estado: str
    motivo: str

    @property
    def fotos_coincidentes(self) -> int:
        return sum(1 for i in self.usadas if self.votos[i] == self.indice)


def combinar(
    preds: list[PrediccionImagen],
    umbrales: Umbrales,
    rechazo: frozenset[int] = frozenset(),
) -> Veredicto:
    if not preds:
        raise ValueError("No hay predicciones para combinar.")

    total = len(preds)
    fuera = tuple(i for i, p in enumerate(preds) if p.fuera_de_distribucion)
    usadas = tuple(i for i in range(total) if i not in fuera) or tuple(range(total))

    media = np.stack([preds[i].probs for i in usadas]).astype(np.float64).mean(axis=0)
    media = media / max(media.sum(), 1e-12)
    indice = decidir(media, rechazo, umbrales.rechazo)
    votos = tuple(decidir(p.probs, rechazo, umbrales.rechazo) for p in preds)
    consistencia = sum(1 for i in usadas if votos[i] == indice) / len(usadas)

    if indice in rechazo:
        # La propia red reconoce que no es una hoja de coca
        estado, motivo = RECHAZADO, "no_es_coca"
        confianza = float(media[sorted(rechazo)].sum())
    else:
        confianza = float(media[indice])
        if len(fuera) / total > umbrales.fraccion_fuera_max:
            estado, motivo = NO_CONCLUYENTE, "fuera_de_distribucion"
        elif confianza >= umbrales.confianza_alta and consistencia >= umbrales.consistencia:
            estado, motivo = CONFIRMADO, "alta_confianza"
        elif confianza >= umbrales.confianza_minima and consistencia >= umbrales.consistencia:
            estado, motivo = PROBABLE, "confianza_media"
        elif confianza >= umbrales.confianza_minima:
            estado, motivo = PROBABLE, "fotos_inconsistentes"
        else:
            estado, motivo = NO_CONCLUYENTE, "baja_confianza"

    return Veredicto(
        probs=media,
        indice=indice,
        confianza=confianza,
        consistencia=consistencia,
        votos=votos,
        usadas=usadas,
        fuera=fuera,
        estado=estado,
        motivo=motivo,
    )


def redactar_mensaje(v: Veredicto, nombre: str) -> str:
    pct = f"{v.confianza * 100:.1f}%"
    coinciden = f"{v.fotos_coincidentes} de {len(v.votos)} fotos coinciden"
    if v.fuera:
        n = len(v.fuera)
        coinciden += f"; {n} foto{'s' if n > 1 else ''} descartada{'s' if n > 1 else ''} por no parecerse a las del entrenamiento"
    mensajes = {
        "alta_confianza": f"Hoja clasificada como «{nombre}» con {pct} de confianza ({coinciden}).",
        "confianza_media": f"Probablemente «{nombre}» ({pct} de confianza). Toma nuevas fotos con buena luz para confirmarlo.",
        "fotos_inconsistentes": (
            f"Probablemente «{nombre}» ({pct}), pero las fotos no coinciden entre sí ({coinciden}). "
            "Repite las fotos con la misma hoja."
        ),
        "baja_confianza": (
            "No se pudo determinar el tipo de hoja con seguridad. "
            "Repite las fotos con buena luz, fondo claro y la hoja centrada."
        ),
        "fuera_de_distribucion": (
            "Las fotos no se parecen a las hojas con las que se entrenó el modelo. "
            "Verifica que sea una hoja de coca y repite las fotos con buena luz y fondo claro."
        ),
        "no_es_coca": (
            f"La red neuronal indica que las fotos no muestran una hoja de coca ({pct} de probabilidad). "
            "Coloca una sola hoja dentro del marco y vuelve a intentarlo."
        ),
    }
    return mensajes.get(v.motivo, f"Resultado: «{nombre}» ({pct}).")
