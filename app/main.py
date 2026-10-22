"""Aplicación FastAPI: carga del modelo al iniciar, CORS, límites y errores en JSON."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool
from starlette.exceptions import HTTPException as StarletteHTTPException

from . import __version__
from .config import Settings, get_settings
from .errores import ErrorAPI
from .rutas import router
from .servicios.reconocimiento import NOMBRE_SERVICIO, ServicioReconocimiento

log = logging.getLogger("api_coca")

DESCRIPCION = """
API de reconocimiento de **tipos de hoja de coca** para el sistema ADEPCOCA.

* Red neuronal convolucional propia **YOLO26-cls** entrenada con el dataset de ADEPCOCA (Roboflow).
  No usa servicios de IA externos: todo el reconocimiento ocurre en este servidor.
* Análisis combinado de varias fotos de la misma hoja con **TTA**, clase **«no es hoja de coca»**
  y detector de fotos ajenas al dataset.
* Explicación en lenguaje natural y **mapas de atención (Grad-CAM)** que muestran dónde se fijó la red.

Prueba los endpoints con el botón **Try it out**. Si configuraste `API_KEY`, pulsa primero **Authorize**.
"""


def configurar_logging(nivel: str) -> None:
    logger = logging.getLogger("api_coca")
    if not logger.handlers:
        manejador = logging.StreamHandler()
        manejador.setFormatter(logging.Formatter("%(asctime)s | %(levelname)-7s | %(message)s", "%Y-%m-%d %H:%M:%S"))
        logger.addHandler(manejador)
    logger.setLevel(nivel)
    logger.propagate = False


def _error(status_code: int, mensaje: str, codigo: str, headers: dict | None = None) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content={"status": "error", "message": mensaje, "codigo": codigo},
        headers=headers,
    )


def crear_app(settings: Settings | None = None, servicio: ServicioReconocimiento | None = None) -> FastAPI:
    settings = settings or get_settings()
    configurar_logging(settings.log_level)

    @asynccontextmanager
    async def ciclo_de_vida(app: FastAPI):
        if servicio is None:
            log.info("Iniciando %s v%s ...", NOMBRE_SERVICIO, __version__)
            nuevo = ServicioReconocimiento(settings)
            await run_in_threadpool(nuevo.iniciar)  # cargar la red tarda unos segundos
            app.state.servicio = nuevo
            if not nuevo.listo:
                log.error("La API quedó en espera: %s", nuevo.clasificador.error)
        yield

    app = FastAPI(
        title=NOMBRE_SERVICIO,
        description=DESCRIPCION,
        version=__version__,
        lifespan=ciclo_de_vida,
        redoc_url=None,
    )
    app.state.settings = settings
    if servicio is not None:
        app.state.servicio = servicio

    if settings.lista_cors:
        app.add_middleware(
            CORSMiddleware,
            allow_origins=settings.lista_cors,
            allow_methods=["GET", "POST"],
            allow_headers=["*"],
        )

    @app.middleware("http")
    async def limitar_tamano(request: Request, call_next):
        """Rechaza de inmediato las subidas enormes, antes de leer el cuerpo."""
        largo = request.headers.get("content-length", "")
        maximo = settings.max_imagenes * settings.max_bytes_por_imagen + 2 * 1024 * 1024
        if request.method == "POST" and largo.isdigit() and int(largo) > maximo:
            return _error(413, f"La petición supera el máximo de {maximo // (1024 * 1024)} MB.", "peticion_muy_grande")
        return await call_next(request)

    @app.exception_handler(ErrorAPI)
    async def _error_api(_request: Request, exc: ErrorAPI):
        return _error(exc.status_code, exc.mensaje, exc.codigo)

    @app.exception_handler(StarletteHTTPException)
    async def _error_http(_request: Request, exc: StarletteHTTPException):
        mensajes = {404: "Ruta no encontrada.", 405: "Método no permitido en esta ruta."}
        return _error(
            exc.status_code,
            mensajes.get(exc.status_code, str(exc.detail)),
            f"http_{exc.status_code}",
            getattr(exc, "headers", None),
        )

    @app.exception_handler(RequestValidationError)
    async def _error_validacion(_request: Request, exc: RequestValidationError):
        detalles = "; ".join(
            f"{'.'.join(str(p) for p in e.get('loc', [])[1:]) or 'petición'}: {e.get('msg')}" for e in exc.errors()
        )
        return _error(422, f"Datos inválidos: {detalles}", "datos_invalidos")

    @app.exception_handler(Exception)
    async def _error_interno(request: Request, exc: Exception):
        log.exception("Error no controlado en %s", request.url.path)
        return _error(500, "Error interno del servicio de reconocimiento.", "error_interno")

    app.include_router(router)
    return app


app = crear_app()
