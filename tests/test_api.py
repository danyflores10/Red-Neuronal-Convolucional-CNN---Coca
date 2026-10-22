"""Endpoints de la API, compatibilidad con Laravel, límites, clave y rechazo de fotos que no son coca."""

import json

from app.servicios.clasificador import ClasificadorCoca
from tests.conftest import AMARILLO, AZUL, GRIS, MAPA_FALSO, ROJO, VERDE, ClasificadorFalso, jpeg


def fotos(*colores, campo="imagenes"):
    return [(campo, (f"foto{i}.jpg", jpeg(c), "image/jpeg")) for i, c in enumerate(colores, start=1)]


# ----------------------------------------------------------------- estado
def test_health_y_status(crear_cliente):
    with crear_cliente() as cliente:
        assert cliente.get("/api/health").json() == {"status": "ok", "modelo_cargado": True}
        estado = cliente.get("/api/status").json()
    assert estado["status"] == "success" and estado["modelo"]["num_clases"] == 4
    assert estado["modelo"]["clase_rechazo"] == ["no_es_coca"]
    assert estado["modelo"]["mapas_atencion"] is True
    assert estado["umbrales"]["confianza_alta"] == 0.80


def test_sin_modelo_la_api_responde_503_con_explicacion(crear_cliente, tmp_path):
    clasificador = ClasificadorCoca(tmp_path / "no_existe.pt")
    with crear_cliente(clasificador=clasificador) as cliente:
        r = cliente.get("/api/health")
        assert r.status_code == 503 and "No se encontró el modelo" in r.json()["message"]
        r = cliente.post("/api/analizar", files=fotos(ROJO))
        assert r.status_code == 503 and r.json()["codigo"] == "modelo_no_disponible"


def test_clases_en_el_formato_que_usa_laravel(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.get("/api/clases").json()
    assert datos["status"] == "success" and datos["total"] == 4
    assert datos["clases"] == ["Primera", "Segunda", "Tercera", "No es hoja de coca"]
    assert set(datos["descripcion"]) == set(datos["clases"])
    assert [c["es_rechazo"] for c in datos["detalle"]] == [False, False, False, True]


# --------------------------------------------------------------- análisis
def test_cinco_fotos_iguales_confirman_la_clase(crear_cliente):
    with crear_cliente() as cliente:
        r = cliente.post("/api/analizar", files=fotos(ROJO, ROJO, ROJO, ROJO, ROJO))
    datos = r.json()
    assert r.status_code == 200
    assert (datos["estado"], datos["clase"], datos["clave"], datos["es_hoja_de_coca"]) == ("confirmado", "Primera", "primera", True)
    assert datos["confianza"] > 0.99 and datos["consistencia"] == 1.0
    assert datos["imagenes_analizadas"] == 5 and len(datos["por_imagen"]) == 5
    assert all(f["es_hoja_de_coca"] for f in datos["por_imagen"])
    assert list(datos["probabilidades"])[0] == "Primera"
    assert datos["metricas"]["confianza_modelo"] > 99


def test_explicacion_en_lenguaje_natural(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/analizar", files=fotos(VERDE, VERDE, VERDE)).json()
    assert datos["explicacion"].startswith("Analicé las 3 fotos con la red neuronal convolucional.")
    assert "«Segunda»" in datos["explicacion"] and "3 de 3 fotos" in datos["explicacion"]


def test_mapas_de_atencion_por_foto(crear_cliente):
    clasificador = ClasificadorFalso()
    with crear_cliente(clasificador=clasificador) as cliente:
        datos = cliente.post("/api/analizar", files=fotos(AZUL, AZUL)).json()
    assert [f["mapa_atencion"] for f in datos["por_imagen"]] == [MAPA_FALSO, MAPA_FALSO]
    assert clasificador.indices_mapas == [2]  # se explica la clase del resultado final («Tercera»)
    assert datos["modelo"]["mapas_atencion"] is True


def test_sin_mapas_de_atencion(crear_cliente):
    with crear_cliente(clasificador=ClasificadorFalso(mapas=False)) as cliente:
        datos = cliente.post("/api/analizar", files=fotos(AZUL)).json()
    assert datos["por_imagen"][0]["mapa_atencion"] is None and datos["modelo"]["mapas_atencion"] is False


def test_la_red_rechaza_lo_que_no_es_hoja_de_coca(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/analizar", files=fotos(AMARILLO, AMARILLO, AMARILLO)).json()
    assert (datos["estado"], datos["motivo"], datos["es_hoja_de_coca"]) == ("rechazado", "no_es_coca", False)
    assert datos["clase"] == "No es hoja de coca" and datos["confianza"] > 0.9
    assert not any(f["es_hoja_de_coca"] for f in datos["por_imagen"])
    assert "no muestran una hoja de coca" in datos["mensaje"]
    assert "no reconozco una hoja de coca" in datos["explicacion"]
    assert datos["recomendaciones"] == ["Fotografía una hoja de coca sola y centrada dentro del marco."]


def test_clase_de_rechazo_con_nombre_personalizado(crear_cliente):
    with crear_cliente(clases_rechazo="tercera") as cliente:
        datos = cliente.post("/api/analizar", files=fotos(AZUL, AZUL)).json()
        estado = cliente.get("/api/status").json()
    assert estado["modelo"]["clase_rechazo"] == ["tercera"]
    assert datos["estado"] == "rechazado"


def test_predecir_mantiene_el_contrato_de_laravel(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/predecir", files=fotos(VERDE, campo="imagen")).json()
    for campo in ("status", "clase", "tipo", "confianza", "descripcion", "recomendacion", "probabilidades", "tiempo_proceso"):
        assert campo in datos
    assert datos["status"] == "success" and datos["clase"] == "Segunda"
    assert 0 <= datos["confianza"] <= 1
    assert all(0 <= p <= 1 for p in datos["probabilidades"].values())


def test_acepta_el_nombre_de_campo_estilo_php(crear_cliente):
    with crear_cliente() as cliente:
        r = cliente.post("/api/analizar", files=fotos(AZUL, AZUL, campo="imagenes[]"))
    assert r.status_code == 200 and r.json()["clase"] == "Tercera"


def test_fotos_contradictorias_no_se_confirman(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/analizar", files=fotos(ROJO, ROJO, VERDE, VERDE, AZUL)).json()
    assert datos["estado"] in ("probable", "no_concluyente")
    assert datos["consistencia"] < 0.6


def test_fotos_ajenas_al_dataset(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/analizar", files=fotos(GRIS, GRIS, GRIS, ROJO, ROJO)).json()
    assert (datos["estado"], datos["motivo"]) == ("no_concluyente", "fuera_de_distribucion")
    assert sum(f["fuera_de_distribucion"] for f in datos["por_imagen"]) == 3
    assert any("no se parece" in a for a in datos["advertencias"])


# ------------------------------------------------------------ validaciones
def test_imagen_invalida(crear_cliente):
    with crear_cliente() as cliente:
        r = cliente.post("/api/analizar", files=[("imagenes", ("roto.jpg", b"no es una foto", "image/jpeg"))])
    assert r.status_code == 422
    assert r.json()["codigo"] == "imagen_invalida" and r.json()["message"].startswith("Foto 1")


def test_sin_imagenes(crear_cliente):
    with crear_cliente() as cliente:
        r = cliente.post("/api/analizar", data={"otro": "campo"})
    assert r.status_code == 422 and r.json()["codigo"] == "sin_imagenes"


def test_maximo_de_imagenes(crear_cliente):
    with crear_cliente(max_imagenes=2) as cliente:
        r = cliente.post("/api/analizar", files=fotos(ROJO, ROJO, ROJO))
    assert r.status_code == 422 and r.json()["codigo"] == "demasiadas_imagenes"


def test_imagen_demasiado_pesada(crear_cliente):
    with crear_cliente(max_mb_por_imagen=0.001) as cliente:  # 1 KB: la foto de prueba pesa más
        r = cliente.post("/api/analizar", files=fotos(ROJO))
    assert r.status_code == 413 and r.json()["codigo"] == "imagen_muy_grande"


def test_rutas_inexistentes_responden_json(crear_cliente):
    with crear_cliente() as cliente:
        r = cliente.get("/api/no-existe")
    assert r.status_code == 404 and r.json()["status"] == "error"


def test_api_key(crear_cliente):
    with crear_cliente(api_key="clave-secreta") as cliente:
        assert cliente.get("/api/health").status_code == 200  # el monitoreo no necesita clave
        assert cliente.post("/api/analizar", files=fotos(ROJO)).status_code == 401
        assert cliente.get("/api/clases", headers={"X-API-Key": "otra"}).status_code == 401
        r = cliente.post("/api/analizar", files=fotos(ROJO), headers={"X-API-Key": "clave-secreta"})
        assert r.status_code == 200


def test_guardar_capturas(crear_cliente, tmp_path):
    with crear_cliente(guardar_capturas=True) as cliente:
        cliente.post("/api/analizar", files=fotos(ROJO, ROJO))
    guardados = list((tmp_path / "capturas").rglob("*"))
    assert sum(p.suffix == ".jpg" for p in guardados) == 2
    assert any(p.name == "resultado.json" for p in guardados)


def test_respuesta_serializable_completa(crear_cliente):
    with crear_cliente() as cliente:
        datos = cliente.post("/api/analizar", files=fotos(ROJO, VERDE)).json()
    json.dumps(datos)  # todo el resultado es JSON válido
    foto = datos["por_imagen"][0]
    assert {"clase", "confianza", "es_hoja_de_coca", "top", "calidad", "similitud_dataset", "mapa_atencion"} <= set(foto)
    assert foto["calidad"]["ancho"] == 320
