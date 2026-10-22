"""Configuración de la API.

Los valores se leen de variables de entorno o del archivo `.env` de la raíz del
proyecto (ver `.env.example`). Las rutas relativas se resuelven siempre desde la
carpeta del proyecto, sin importar desde dónde se inicie el servidor.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE_DIR = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=BASE_DIR / ".env",
        env_file_encoding="utf-8",
        env_ignore_empty=True,  # una variable vacía en .env = usar el valor por defecto
        extra="ignore",
    )

    # --- Servidor ---
    host: str = "127.0.0.1"
    port: int = Field(default=5000, ge=1, le=65535)
    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"
    api_key: str = ""  # si se define, se exige el encabezado X-API-Key
    cors_origins: str = "http://127.0.0.1:8000,http://localhost:8000"

    # --- Modelo entrenado (archivos que entrega el notebook de Colab) ---
    modelo_path: Path = Path("weights/best.pt")
    modelo_meta_path: Path = Path("weights/model_meta.json")
    ood_path: Path = Path("weights/ood_embeddings.npz")
    clases_path: Path = Path("config/clases.json")
    device: str = "cpu"  # cpu | auto | cuda:0
    imgsz: int = Field(default=0, ge=0, le=1280)  # 0 = el tamaño usado en el entrenamiento
    tta: bool | None = None  # None = lo que recomendó el notebook
    mapas_atencion: bool = True  # Grad-CAM: dónde se fijó la red en cada foto
    # Nombres de la clase "no es hoja de coca" (separados por coma). Vacío = los nombres habituales.
    clases_rechazo: str = ""

    # --- Umbrales de decisión (None = los calculados por el notebook) ---
    umbral_confianza_alta: float | None = Field(default=None, ge=0, le=1)
    umbral_confianza_minima: float | None = Field(default=None, ge=0, le=1)
    umbral_consistencia: float = Field(default=0.6, ge=0, le=1)

    # --- Límites de las peticiones ---
    max_imagenes: int = Field(default=10, ge=1, le=30)
    max_mb_por_imagen: float = Field(default=10, gt=0, le=50)

    # --- Guardar las fotos recibidas para re-entrenar el modelo ---
    guardar_capturas: bool = False
    capturas_dir: Path = Path("data/capturas")

    @field_validator("modelo_path", "modelo_meta_path", "ood_path", "clases_path", "capturas_dir")
    @classmethod
    def _ruta_absoluta(cls, ruta: Path) -> Path:
        return ruta if ruta.is_absolute() else (BASE_DIR / ruta).resolve()

    @field_validator("log_level", mode="before")
    @classmethod
    def _nivel_en_mayusculas(cls, valor: str) -> str:
        return str(valor).strip().upper()

    @property
    def lista_cors(self) -> list[str]:
        return [origen.strip() for origen in self.cors_origins.split(",") if origen.strip()]

    @property
    def max_bytes_por_imagen(self) -> int:
        return int(self.max_mb_por_imagen * 1024 * 1024)

    @property
    def nombres_rechazo(self) -> list[str]:
        return [n.strip() for n in self.clases_rechazo.split(",") if n.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()
