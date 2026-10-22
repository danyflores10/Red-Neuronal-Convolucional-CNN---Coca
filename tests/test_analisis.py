"""Reglas del veredicto: promedio de fotos, umbrales, clase «no es hoja de coca» y explicación."""

import numpy as np
import pytest

from app.servicios.analisis import (
    CONFIRMADO,
    NO_CONCLUYENTE,
    PROBABLE,
    RECHAZADO,
    PrediccionImagen,
    Umbrales,
    combinar,
    decidir,
    redactar_mensaje,
)
from app.servicios.catalogo import Catalogo, InfoClase
from app.servicios.explicacion import redactar_explicacion
from app.servicios.imagenes import CalidadImagen

U = Umbrales(confianza_alta=0.80, confianza_minima=0.55, consistencia=0.60)
NO_COCA = frozenset({3})


def pred(*probs, fuera=False):
    return PrediccionImagen(probs=np.array(probs, dtype=float), distancia_ood=0.3 if fuera else 0.05, fuera_de_distribucion=fuera)


def catalogo():
    nombres = ["Primera", "Segunda", "Tercera", "No es hoja de coca"]
    return Catalogo([
        InfoClase(i, n.lower().replace(" ", "_"), n, "Hoja entera y verde intenso." if i == 0 else "", "")
        for i, n in enumerate(nombres)
    ])


def calidad(iluminacion_brillo=0.55, nitidez=0.5):
    return CalidadImagen(ancho=320, alto=240, brillo=iluminacion_brillo, contraste=0.4, nitidez=nitidez)


# ------------------------------------------------------------- combinación
def test_cinco_fotos_coincidentes_confirman():
    v = combinar([pred(0.95, 0.03, 0.02)] * 5, U)
    assert (v.estado, v.motivo, v.indice) == (CONFIRMADO, "alta_confianza", 0)
    assert v.consistencia == 1.0
    assert v.confianza == pytest.approx(0.95)


def test_promedio_suave_de_probabilidades():
    v = combinar([pred(0.9, 0.1, 0.0), pred(0.5, 0.5, 0.0)], U)
    assert v.probs == pytest.approx([0.7, 0.3, 0.0])
    assert v.estado == PROBABLE and v.motivo == "confianza_media"


def test_fotos_que_no_coinciden_no_confirman():
    preds = [pred(0.99, 0.01, 0)] * 2 + [pred(0.35, 0.40, 0.25)] * 3
    v = combinar(preds, U)
    assert v.indice == 0 and v.consistencia == pytest.approx(0.4)
    assert (v.estado, v.motivo) == (PROBABLE, "fotos_inconsistentes")


def test_baja_confianza_no_concluyente():
    v = combinar([pred(0.40, 0.35, 0.25)] * 5, U)
    assert (v.estado, v.motivo) == (NO_CONCLUYENTE, "baja_confianza")


def test_mayoria_de_fotos_ajenas_al_dataset():
    preds = [pred(0.99, 0.01, 0, fuera=True)] * 3 + [pred(0.99, 0.01, 0)] * 2
    v = combinar(preds, U)
    assert (v.estado, v.motivo) == (NO_CONCLUYENTE, "fuera_de_distribucion")
    assert v.fuera == (0, 1, 2) and v.usadas == (3, 4)


def test_fotos_ajenas_no_entran_al_promedio():
    preds = [pred(0.1, 0.9, 0, fuera=True)] + [pred(0.9, 0.1, 0)] * 4
    v = combinar(preds, U)
    assert v.indice == 0 and v.usadas == (1, 2, 3, 4)
    assert v.confianza == pytest.approx(0.9)


def test_sin_predicciones_es_error():
    with pytest.raises(ValueError):
        combinar([], U)


# ---------------------------------------------------- «no es hoja de coca»
def test_rechaza_cuando_no_es_coca_supera_la_mitad():
    v = combinar([pred(0.10, 0.05, 0.05, 0.80)] * 5, U, NO_COCA)
    assert (v.estado, v.motivo, v.indice) == (RECHAZADO, "no_es_coca", 3)
    assert v.confianza == pytest.approx(0.80)


def test_no_rechaza_si_la_mayor_parte_es_coca_aunque_este_repartida():
    # «no es coca» es la clase individual más alta (0.40) pero la hoja de coca suma 0.60
    probs = np.array([0.30, 0.25, 0.05, 0.40])
    assert decidir(probs, NO_COCA) == 0
    assert int(np.argmax(probs)) == 3
    v = combinar([pred(*probs)] * 5, U, NO_COCA)
    assert v.estado != RECHAZADO and v.indice == 0


def test_cada_foto_vota_con_la_misma_regla():
    preds = [pred(0.85, 0.05, 0.05, 0.05)] * 3 + [pred(0.05, 0.05, 0.05, 0.85)] * 2
    v = combinar(preds, U, NO_COCA)
    assert v.votos == (0, 0, 0, 3, 3) and v.indice == 0


def test_rechazo_tiene_prioridad_sobre_fotos_ajenas():
    v = combinar([pred(0.05, 0.05, 0.05, 0.85, fuera=True)] * 5, U, NO_COCA)
    assert v.estado == RECHAZADO


def test_sin_clase_de_rechazo_se_usa_la_mas_probable():
    assert decidir(np.array([0.2, 0.7, 0.1])) == 1


# ------------------------------------------------------- mensajes y explicación
def test_mensajes_en_espanol_con_clase_y_porcentaje():
    v = combinar([pred(0.95, 0.03, 0.02)] * 5, U)
    mensaje = redactar_mensaje(v, "Primera")
    assert "«Primera»" in mensaje and "95.0%" in mensaje and "5 de 5" in mensaje


def test_mensaje_menciona_fotos_descartadas():
    preds = [pred(0.1, 0.9, 0, fuera=True)] + [pred(0.95, 0.05, 0)] * 4
    mensaje = redactar_mensaje(combinar(preds, U), "Primera")
    assert "(4 de 5 fotos coinciden; 1 foto descartada por no parecerse a las del entrenamiento)." in mensaje


def test_explicacion_resultado_confirmado():
    v = combinar([pred(0.90, 0.06, 0.02, 0.02)] * 5, U, NO_COCA)
    texto = redactar_explicacion(v, catalogo(), [calidad()] * 5, NO_COCA)
    assert texto.startswith("Analicé las 5 fotos con la red neuronal convolucional.")
    assert "«Primera», con 90.0 % de confianza" in texto
    assert "«Segunda», obtuvo 6.0 % (diferencia amplia)" in texto
    assert "5 de 5 fotos" in texto and "Rasgos de este tipo: Hoja entera y verde intenso." in texto
    assert "No es hoja de coca" not in texto  # la clase de rechazo no aparece como "segunda opción"
    assert texto.endswith("el resultado es confiable.")


def test_explicacion_de_rechazo_y_de_una_sola_foto():
    v = combinar([pred(0.05, 0.05, 0.05, 0.85)], U, NO_COCA)
    texto = redactar_explicacion(v, catalogo(), [calidad()], NO_COCA)
    assert texto.startswith("Analicé la foto") and "no reconozco una hoja de coca" in texto and "85.0 %" in texto


def test_explicacion_menciona_calidad_deficiente():
    v = combinar([pred(0.6, 0.3, 0.1)] * 2, U)
    texto = redactar_explicacion(v, catalogo(), [calidad(iluminacion_brillo=0.05, nitidez=0.01)] * 2)
    assert "iluminación fue deficiente" in texto and "nitidez baja" in texto
    assert "conviene repetir las fotos" in texto
