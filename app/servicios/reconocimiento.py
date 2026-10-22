"""Orquesta el análisis: fotos -> red neuronal -> veredicto -> explicación -> respuesta.

Todo el reconocimiento lo hace la red neuronal propia (YOLO26-cls entrenada con el
dataset de ADEPCOCA); no se usa ningún servicio de IA externo.
"""

from __future__ import annotations

import json
import logging
import time
from dataclasses import asdict
from pathlib import Path

import numpy as np

from .. import __version__
from ..config import Settings
from ..errores import ErrorAPI
from ..schemas import (
    CalidadFoto,
    ClaseProbabilidad,
    InfoClase,
    InfoModelo,
    Metricas,
    RespuestaAnalisis,
    RespuestaClases,
    ResultadoFoto,
)
from .analisis import (
    CONFIRMADO,
    NO_CONCLUYENTE,
    PROBABLE,
    RECHAZADO,
    PrediccionImagen,
    Umbrales,
    Veredicto,
    combinar,
    decidir,
    redactar_mensaje,
)
from .capturas import guardar_capturas
from .catalogo import Catalogo
from .clasificador import ClasificadorCoca
from .explicacion import redactar_explicacion
from .imagenes import CalidadImagen, ImagenInvalida, decodificar_imagen, medir_calidad

log = logging.getLogger("api_coca")

NOMBRE_SERVICIO = "API Reconocimiento de Hoja de Coca - ADEPCOCA"
MAX_RECOMENDACIONES = 6
RECOMENDACION_POR_ESTADO = {
    PROBABLE: "Toma 5 fotos nuevas con luz natural y la hoja centrada para confirmar el resultado.",
    NO_CONCLUYENTE: "Repite la captura: una sola hoja, centrada, sobre fondo claro, con luz natural y sin sombras.",
    RECHAZADO: "Fotografía una hoja de coca sola y centrada dentro del marco.",
}


def leer_meta(ruta: Path) -> dict:
    if not ruta.exists():
        log.warning("Sin %s: se usarán los umbrales por defecto.", ruta.name)
        return {}
    try:
        meta = json.loads(ruta.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        log.error("No se pudo leer %s: %s", ruta, exc)
        return {}
    return meta if isinstance(meta, dict) else {}


def _p(valor: float) -> float:
    """Probabilidad 0-1 redondeada (y protegida contra errores de redondeo)."""
    return round(min(max(float(valor), 0.0), 1.0), 4)


def _pct(valor: float) -> float:
    return round(min(max(float(valor), 0.0), 1.0) * 100, 1)


class ServicioReconocimiento:
    def __init__(self, settings: Settings, clasificador: ClasificadorCoca | None = None):
        self.settings = settings
        self.clasificador = clasificador or ClasificadorCoca(
            ruta_modelo=settings.modelo_path,
            ruta_ood=settings.ood_path,
            device=settings.device,
            imgsz=settings.imgsz,
            tta=settings.tta,
            mapas_atencion=settings.mapas_atencion,
        )
        self.catalogo = Catalogo([])
        self.rechazo: frozenset[int] = frozenset()
        self.meta: dict = {}
        self.umbrales = Umbrales()
        self._inicio = time.time()

    @property
    def listo(self) -> bool:
        return self.clasificador.listo

    # ------------------------------------------------------------------ inicio
    def iniciar(self) -> None:
        meta = leer_meta(self.settings.modelo_meta_path)
        self.clasificador.cargar(meta)
        if not self.clasificador.listo:
            return

        self.meta = self._meta_compatible(meta)
        self.umbrales = self._resolver_umbrales()
        self.catalogo = Catalogo.desde_archivo(self.settings.clases_path, self.clasificador.nombres)
        nombres_rechazo = self.settings.nombres_rechazo or self.meta.get("clases_rechazo") or None
        self.rechazo = self.catalogo.indices_rechazo(nombres_rechazo)

        log.info(
            "Umbrales: confianza alta=%.2f, mínima=%.2f, consistencia=%.2f | Clase «no es hoja de coca»: %s",
            self.umbrales.confianza_alta,
            self.umbrales.confianza_minima,
            self.umbrales.consistencia,
            ", ".join(self.catalogo[i].clave for i in sorted(self.rechazo)) or "no tiene (solo detector OOD)",
        )

    def _meta_compatible(self, meta: dict) -> dict:
        clases_meta = meta.get("clases")
        clases_modelo = [self.clasificador.nombres[i] for i in sorted(self.clasificador.nombres)]
        if clases_meta is not None and list(clases_meta) != clases_modelo:
            log.warning(
                "model_meta.json no corresponde a %s (clases distintas). Se ignoran sus umbrales.",
                self.settings.modelo_path.name,
            )
            return {}
        return meta

    def _resolver_umbrales(self) -> Umbrales:
        del_notebook = self.meta.get("umbrales") or {}

        def valor(configurado: float | None, clave: str, defecto: float) -> float:
            if configurado is not None:
                return float(configurado)
            try:
                v = float(del_notebook.get(clave, defecto))
            except (TypeError, ValueError):
                return defecto
            return v if 0.0 <= v <= 1.0 else defecto

        s = self.settings
        alta = valor(s.umbral_confianza_alta, "confianza_alta", Umbrales.confianza_alta)
        minima = valor(s.umbral_confianza_minima, "confianza_minima", Umbrales.confianza_minima)
        if minima > alta:
            log.warning("El umbral mínimo (%.2f) supera al alto (%.2f); se iguala al alto.", minima, alta)
            minima = alta
        return Umbrales(confianza_alta=alta, confianza_minima=minima, consistencia=s.umbral_consistencia)

    # ---------------------------------------------------------------- análisis
    def analizar(self, archivos: list[tuple[str, bytes]]) -> RespuestaAnalisis:
        if not self.listo:
            raise ErrorAPI(self.clasificador.error or "El modelo no está disponible.", 503, "modelo_no_disponible")
        inicio = time.perf_counter()

        imagenes, calidades = [], []
        for numero, (nombre, datos) in enumerate(archivos, start=1):
            try:
                imagen = decodificar_imagen(datos, nombre)
            except ImagenInvalida as exc:
                raise ErrorAPI(f"Foto {numero}: {exc}", 422, "imagen_invalida") from exc
            imagenes.append(imagen)
            calidades.append(medir_calidad(imagen))

        preds = self.clasificador.predecir(imagenes)
        veredicto = combinar(preds, self.umbrales, self.rechazo)
        mapas = self.clasificador.mapas_atencion(imagenes, veredicto.indice)

        respuesta = self._respuesta(
            archivos, preds, calidades, mapas, veredicto, time.perf_counter() - inicio
        )
        if self.settings.guardar_capturas:
            guardar_capturas(self.settings.capturas_dir, imagenes, respuesta)

        log.info(
            "Análisis de %d foto(s): %s «%s» %.1f%% (consistencia %.0f%%) en %.2f s",
            len(archivos),
            respuesta.estado,
            respuesta.clase,
            respuesta.confianza * 100,
            respuesta.consistencia * 100,
            respuesta.tiempo_proceso,
        )
        return respuesta

    def _respuesta(
        self,
        archivos: list[tuple[str, bytes]],
        preds: list[PrediccionImagen],
        calidades: list[CalidadImagen],
        mapas: list[str | None],
        v: Veredicto,
        segundos: float,
    ) -> RespuestaAnalisis:
        cat = self.catalogo
        info = cat[v.indice]
        ood = self.clasificador.ood
        orden = [int(i) for i in np.argsort(-v.probs, kind="stable")]

        por_imagen = []
        for i, (pred, calidad) in enumerate(zip(preds, calidades)):
            elegida = decidir(pred.probs, self.rechazo, self.umbrales.rechazo)
            confianza = pred.probs[sorted(self.rechazo)].sum() if elegida in self.rechazo else pred.probs[elegida]
            top_foto = [int(j) for j in np.argsort(-pred.probs, kind="stable")[:3]]
            similitud = None if ood is None or pred.distancia_ood is None else ood.similitud(pred.distancia_ood)
            por_imagen.append(
                ResultadoFoto(
                    indice=i + 1,
                    archivo=archivos[i][0],
                    clase=cat[elegida].nombre,
                    clave=cat[elegida].clave,
                    confianza=_p(confianza),
                    coincide_con_resultado=elegida == v.indice,
                    es_hoja_de_coca=elegida not in self.rechazo,
                    usada_en_promedio=i in v.usadas,
                    fuera_de_distribucion=pred.fuera_de_distribucion,
                    similitud_dataset=None if similitud is None else round(similitud, 4),
                    top=[
                        ClaseProbabilidad(clave=cat[j].clave, nombre=cat[j].nombre, probabilidad=_p(pred.probs[j]))
                        for j in top_foto
                    ],
                    calidad=CalidadFoto(
                        ancho=calidad.ancho,
                        alto=calidad.alto,
                        brillo=calidad.brillo,
                        contraste=calidad.contraste,
                        nitidez=calidad.nitidez,
                        iluminacion=round(calidad.iluminacion, 4),
                        advertencias=calidad.advertencias,
                    ),
                    mapa_atencion=mapas[i] if i < len(mapas) else None,
                )
            )

        advertencias = [f"Foto {n}: {a}" for n, c in enumerate(calidades, start=1) for a in c.advertencias]
        advertencias += [
            f"Foto {n}: no se parece a las imágenes de entrenamiento (otro objeto, fondo o luz muy distinta)."
            for n, p in enumerate(preds, start=1)
            if p.fuera_de_distribucion
        ]

        recomendaciones = []
        if v.estado in (CONFIRMADO, PROBABLE) and info.recomendacion:
            recomendaciones.append(info.recomendacion)
        if v.estado in RECOMENDACION_POR_ESTADO:
            recomendaciones.append(RECOMENDACION_POR_ESTADO[v.estado])
        recomendaciones = list(dict.fromkeys(recomendaciones))[:MAX_RECOMENDACIONES]

        similitudes = [f.similitud_dataset for f in por_imagen if f.similitud_dataset is not None]
        es_coca = v.estado != RECHAZADO

        return RespuestaAnalisis(
            estado=v.estado,
            motivo=v.motivo,
            mensaje=redactar_mensaje(v, info.nombre),
            explicacion=redactar_explicacion(v, cat, calidades, self.rechazo),
            es_hoja_de_coca=es_coca,
            clase=info.nombre,
            tipo=info.nombre,
            clave=info.clave,
            indice_clase=info.indice,
            confianza=_p(v.confianza),
            descripcion=info.descripcion,
            recomendacion=info.recomendacion,
            recomendaciones=recomendaciones,
            probabilidades={cat[i].nombre: _p(v.probs[i]) for i in orden},
            top=[ClaseProbabilidad(clave=cat[i].clave, nombre=cat[i].nombre, probabilidad=_p(v.probs[i])) for i in orden[:3]],
            consistencia=_p(v.consistencia),
            imagenes_analizadas=len(preds),
            imagenes_validas=len(v.usadas),
            por_imagen=por_imagen,
            metricas=Metricas(
                confianza_modelo=_pct(v.confianza),
                consistencia=_pct(v.consistencia),
                similitud_dataset=_pct(float(np.mean(similitudes))) if similitudes else None,
                iluminacion=_pct(float(np.mean([c.iluminacion for c in calidades]))),
                nitidez=_pct(float(np.mean([c.nitidez for c in calidades]))),
            ),
            advertencias=advertencias,
            modelo=InfoModelo(
                arquitectura=self.clasificador.arquitectura,
                archivo=self.clasificador.ruta_modelo.name,
                imgsz=self.clasificador.imgsz,
                tta=self.clasificador.tta,
                detector_ood=ood is not None,
                mapas_atencion=self.clasificador.mapas_activos,
                clase_rechazo=bool(self.rechazo),
                version_api=__version__,
            ),
            tiempo_proceso=round(segundos, 3),
        )

    # ------------------------------------------------------------ información
    def clases(self) -> RespuestaClases:
        if not self.listo:
            raise ErrorAPI(self.clasificador.error or "El modelo no está disponible.", 503, "modelo_no_disponible")
        detalle = [
            InfoClase(
                indice=c.indice,
                clave=c.clave,
                nombre=c.nombre,
                descripcion=c.descripcion,
                recomendacion=c.recomendacion,
                es_rechazo=c.indice in self.rechazo,
            )
            for c in self.catalogo.clases
        ]
        return RespuestaClases(
            total=len(detalle),
            clases=[c.nombre for c in detalle],
            claves=[c.clave for c in detalle],
            descripcion={c.nombre: c.descripcion for c in detalle},
            detalle=detalle,
        )

    def estado(self) -> dict:
        s = self.settings
        base = {
            "servicio": NOMBRE_SERVICIO,
            "version": __version__,
            "modelo_cargado": self.listo,
            "tiempo_activo_s": int(time.time() - self._inicio),
        }
        if not self.listo:
            return {**base, "status": "error", "message": self.clasificador.error}

        ood = self.clasificador.ood
        return {
            **base,
            "status": "success",
            "modelo": {
                "archivo": self.clasificador.ruta_modelo.name,
                "arquitectura": self.clasificador.arquitectura,
                "imgsz": self.clasificador.imgsz,
                "tta": self.clasificador.tta,
                "device": self.clasificador.device,
                "num_clases": len(self.catalogo),
                "clases": [c.nombre for c in self.catalogo.clases],
                "clase_rechazo": [self.catalogo[i].clave for i in sorted(self.rechazo)],
                "mapas_atencion": self.clasificador.mapas_activos,
                "entrenado": self.meta.get("fecha_entrenamiento"),
                "metricas_test": self.meta.get("metricas_test"),
                "ultralytics_entrenamiento": self.meta.get("ultralytics_version"),
            },
            "umbrales": asdict(self.umbrales),
            "detector_ood": {"activo": ood is not None, "umbral": ood.umbral if ood else None, "k": ood.k if ood else None},
            "limites": {"max_imagenes": s.max_imagenes, "max_mb_por_imagen": s.max_mb_por_imagen},
            "requiere_api_key": bool(s.api_key),
        }
