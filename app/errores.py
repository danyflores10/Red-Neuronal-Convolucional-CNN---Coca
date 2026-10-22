"""Errores de la API con mensaje en español y código estable para el cliente (Laravel)."""


class ErrorAPI(Exception):
    def __init__(self, mensaje: str, status_code: int = 400, codigo: str = "solicitud_invalida"):
        super().__init__(mensaje)
        self.mensaje = mensaje
        self.status_code = status_code
        self.codigo = codigo
