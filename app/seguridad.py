"""Autenticación por clave compartida con Laravel (encabezado X-API-Key)."""

import secrets

from fastapi import Request, Security
from fastapi.security import APIKeyHeader

from .errores import ErrorAPI

encabezado_api_key = APIKeyHeader(
    name="X-API-Key",
    auto_error=False,
    description="Clave compartida con Laravel (API_KEY del .env). Solo se exige si API_KEY tiene valor.",
)


def verificar_api_key(request: Request, clave: str | None = Security(encabezado_api_key)) -> None:
    esperada = request.app.state.settings.api_key
    if not esperada:
        return
    if not clave or not secrets.compare_digest(clave.encode("utf-8"), esperada.encode("utf-8")):
        raise ErrorAPI("Falta el encabezado X-API-Key o la clave no es válida.", 401, "no_autorizado")
