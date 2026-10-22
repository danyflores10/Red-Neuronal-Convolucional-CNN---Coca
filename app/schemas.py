"""Formato de las respuestas JSON (documentado automáticamente en /docs).

Los campos `status`, `clase`, `tipo`, `confianza` (0-1), `descripcion`,
`recomendacion`, `probabilidades` y `tiempo_proceso` mantienen el formato que ya
consume `CocaClassifierService` en Laravel.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class ClaseProbabilidad(BaseModel):
    clave: str
    nombre: str
    probabilidad: float = Field(ge=0, le=1)


class CalidadFoto(BaseModel):
    ancho: int
    alto: int
    brillo: float
    contraste: float
    nitidez: float
    iluminacion: float
    advertencias: list[str] = []


class ResultadoFoto(BaseModel):
    indice: int = Field(description="Número de foto (1, 2, ...)")
    archivo: str
    clase: str
    clave: str
    confianza: float = Field(ge=0, le=1)
    coincide_con_resultado: bool
    es_hoja_de_coca: bool = Field(description="La red ve una hoja de coca en esta foto (no la clase de rechazo)")
    usada_en_promedio: bool
    fuera_de_distribucion: bool = Field(description="La foto no se parece a las imágenes de entrenamiento")
    similitud_dataset: float | None = Field(default=None, description="0-1; desde 0.5 está dentro de lo aprendido")
    top: list[ClaseProbabilidad]
    calidad: CalidadFoto
    mapa_atencion: str | None = Field(
        default=None, description="Grad-CAM: dónde se fijó la red (imagen JPEG como data URI)"
    )


class Metricas(BaseModel):
    """Valores 0-100 para las barras de la interfaz."""

    confianza_modelo: float
    consistencia: float
    similitud_dataset: float | None
    iluminacion: float
    nitidez: float


class InfoModelo(BaseModel):
    arquitectura: str
    archivo: str
    imgsz: int
    tta: bool
    detector_ood: bool
    mapas_atencion: bool
    clase_rechazo: bool = Field(description="El modelo tiene la clase «no es hoja de coca»")
    version_api: str


class RespuestaAnalisis(BaseModel):
    status: Literal["success"] = "success"
    estado: Literal["confirmado", "probable", "no_concluyente", "rechazado"]
    motivo: str
    mensaje: str
    explicacion: str = Field(description="Informe en lenguaje natural redactado por la API (sin modelos externos)")
    es_hoja_de_coca: bool
    clase: str = Field(description="Nombre visible de la clase ganadora")
    tipo: str
    clave: str = Field(description="Nombre de la clase en el modelo (carpeta del dataset)")
    indice_clase: int
    confianza: float = Field(ge=0, le=1)
    descripcion: str
    recomendacion: str
    recomendaciones: list[str]
    probabilidades: dict[str, float] = Field(description="Nombre visible -> probabilidad (0-1), de mayor a menor")
    top: list[ClaseProbabilidad]
    consistencia: float = Field(ge=0, le=1, description="Fracción de fotos que coinciden con el resultado")
    imagenes_analizadas: int
    imagenes_validas: int
    por_imagen: list[ResultadoFoto]
    metricas: Metricas
    advertencias: list[str]
    modelo: InfoModelo
    tiempo_proceso: float = Field(description="Segundos")


class InfoClase(BaseModel):
    indice: int
    clave: str
    nombre: str
    descripcion: str
    recomendacion: str
    es_rechazo: bool = False


class RespuestaClases(BaseModel):
    status: Literal["success"] = "success"
    total: int
    clases: list[str] = Field(description="Nombres visibles, en el orden del modelo")
    claves: list[str]
    descripcion: dict[str, str] = Field(description="Nombre visible -> descripción")
    detalle: list[InfoClase]


class RespuestaError(BaseModel):
    status: Literal["error"] = "error"
    message: str
    codigo: str
