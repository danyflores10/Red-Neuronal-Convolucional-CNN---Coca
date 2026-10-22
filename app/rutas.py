"""Endpoints de la API.

Compatibles con `CocaClassifierService` de Laravel:
  GET  /api/health    -> verificación rápida (200 si el modelo está cargado)
  GET  /api/status    -> estado detallado
  GET  /api/clases    -> clases del modelo con nombre y descripción
  POST /api/predecir  -> una foto (campo 'imagen')
  POST /api/analizar  -> varias fotos de la misma hoja (campo 'imagenes'), análisis combinado
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Request, UploadFile
from fastapi.responses import JSONResponse, RedirectResponse
from starlette.concurrency import run_in_threadpool

from .errores import ErrorAPI
from .schemas import RespuestaAnalisis, RespuestaClases, RespuestaError
from .seguridad import verificar_api_key
from .servicios.reconocimiento import ServicioReconocimiento

ERRORES = {
    401: {"model": RespuestaError, "description": "Falta X-API-Key o no es válida"},
    413: {"model": RespuestaError, "description": "Imagen demasiado grande"},
    422: {"model": RespuestaError, "description": "Imagen inválida o datos incorrectos"},
    503: {"model": RespuestaError, "description": "El modelo no está cargado"},
}

router = APIRouter()
protegido = APIRouter(dependencies=[Depends(verificar_api_key)])


def _servicio(request: Request) -> ServicioReconocimiento:
    return request.app.state.servicio


async def _leer_archivos(request: Request, archivos: list[UploadFile], maximo: int) -> list[tuple[str, bytes]]:
    settings = request.app.state.settings
    if not archivos:
        raise ErrorAPI(
            "No se recibió ninguna imagen. Envía las fotos como multipart/form-data en el campo 'imagenes'.",
            422,
            "sin_imagenes",
        )
    if len(archivos) > maximo:
        raise ErrorAPI(
            f"Se recibieron {len(archivos)} imágenes; el máximo por análisis es {maximo}.", 422, "demasiadas_imagenes"
        )

    limite = settings.max_bytes_por_imagen
    leidos = []
    for numero, archivo in enumerate(archivos, start=1):
        datos = await archivo.read(limite + 1)
        await archivo.close()
        if len(datos) > limite:
            raise ErrorAPI(
                f"La foto {numero} ('{archivo.filename}') supera el máximo de {settings.max_mb_por_imagen:g} MB.",
                413,
                "imagen_muy_grande",
            )
        leidos.append((archivo.filename or f"foto_{numero}.jpg", datos))
    return leidos


@router.get("/", include_in_schema=False)
def inicio():
    return RedirectResponse("/docs")


@router.get("/api/health", tags=["Estado"], summary="Verificación rápida (200 si el modelo está listo)")
def health(request: Request):
    servicio = _servicio(request)
    if servicio.listo:
        return {"status": "ok", "modelo_cargado": True}
    return JSONResponse(
        status_code=503,
        content={"status": "error", "modelo_cargado": False, "message": servicio.clasificador.error},
    )


@protegido.get("/api/status", tags=["Estado"], summary="Estado detallado del modelo y de la configuración")
def status(request: Request):
    datos = _servicio(request).estado()
    return JSONResponse(status_code=200 if datos["status"] == "success" else 503, content=datos)


@protegido.get(
    "/api/clases",
    response_model=RespuestaClases,
    responses=ERRORES,
    tags=["Clasificación"],
    summary="Clases que reconoce el modelo",
)
def clases(request: Request):
    return _servicio(request).clases()


@protegido.post(
    "/api/predecir",
    response_model=RespuestaAnalisis,
    responses=ERRORES,
    tags=["Clasificación"],
    summary="Clasifica una sola foto",
)
async def predecir(
    request: Request,
    imagen: UploadFile | None = File(None, description="Foto de la hoja (JPG, PNG o WEBP)"),
):
    archivos = await _leer_archivos(request, [imagen] if imagen else [], maximo=1)
    return await run_in_threadpool(_servicio(request).analizar, archivos)


@protegido.post(
    "/api/analizar",
    response_model=RespuestaAnalisis,
    responses=ERRORES,
    tags=["Clasificación"],
    summary="Análisis combinado de varias fotos de la misma hoja (recomendado: 5)",
)
async def analizar(
    request: Request,
    imagenes: list[UploadFile] | None = File(None, description="Fotos de la misma hoja desde distintos ángulos"),
    imagenes_php: list[UploadFile] | None = File(None, alias="imagenes[]", include_in_schema=False),
    imagen: list[UploadFile] | None = File(None, include_in_schema=False),
):
    recibidos = [*(imagenes or []), *(imagenes_php or []), *(imagen or [])]
    archivos = await _leer_archivos(request, recibidos, maximo=request.app.state.settings.max_imagenes)
    return await run_in_threadpool(_servicio(request).analizar, archivos)


router.include_router(protegido)
