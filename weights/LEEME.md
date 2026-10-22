# Carpeta del modelo entrenado

Aquí van los archivos que genera el notebook de Colab (`notebooks/Entrenamiento_CNN_Hoja_Coca_YOLO26.ipynb`)
dentro de `modelo_coca_api.zip`:

| Archivo | Qué es | ¿Obligatorio? |
|---|---|---|
| `best.pt` | La red neuronal entrenada (YOLO26-cls) | **Sí** |
| `model_meta.json` | Clases, tamaño de entrada, TTA, umbrales de decisión y métricas | Recomendado |
| `ood_embeddings.npz` | Banco de referencia del detector de fotos ajenas al dataset | Recomendado |

La forma más simple: **descomprime el ZIP dentro de la carpeta `API COCA`** (crea/reemplaza esta carpeta `weights/`
y agrega la carpeta `reporte/` con las gráficas del entrenamiento). Después reinicia la API.

Sin `best.pt` la API arranca igual, pero responde `503` con un mensaje que explica qué falta.
