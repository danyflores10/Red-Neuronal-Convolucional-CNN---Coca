# 🌿 API de Reconocimiento de Hoja de Coca — ADEPCOCA

API que reconoce los **tipos de hoja de coca** a partir de fotos con una **red neuronal convolucional propia
(YOLO26-cls)**, entrenada con el dataset de ADEPCOCA (Roboflow). Es el motor del módulo **Reconocimiento de Coca**
de SIS-ADEPCOCA.

**Es tu propia IA: una red neuronal convolucional (CNN).** Todo el reconocimiento ocurre en este servidor, con la red
entrenada con tus fotos. Además de clasificar el tipo de hoja, sabe decir *«esto no es una hoja de coca»*, explica su
resultado en lenguaje natural y muestra **en qué parte de la hoja se fijó**.

```
Navegador (cámara, 5 fotos)
   └─► Laravel SIS-ADEPCOCA   POST /reconocimiento-coca/analizar     (sesión + CSRF + rol)
          └─► API COCA (FastAPI, puerto 5000)   POST /api/analizar    (X-API-Key)
                 ├─ 1. Lectura segura de cada foto (EXIF, tamaño, formato) y su calidad (luz, nitidez)
                 ├─ 2. Red neuronal YOLO26-cls con TTA (4 vistas por foto)
                 ├─ 3. ¿Es hoja de coca?  → clase «no es hoja de coca» + detector de fotos ajenas (OOD)
                 ├─ 4. ¿Qué tipo?         → veredicto combinado de las fotos con umbrales calibrados
                 └─ 5. Explicación en lenguaje natural + mapas de atención Grad-CAM de cada foto
```

## Qué la hace precisa y confiable

| Técnica | Para qué sirve |
|---|---|
| **Transfer learning** desde ImageNet (YOLO26l-cls, 79 % top-1 en ImageNet) | Aprende los tipos de hoja con pocos cientos de fotos por clase |
| **Preparación honesta del dataset** | Elimina fotos dañadas, duplicados y fugas entre entrenamiento y prueba, y balancea las clases |
| **Early stopping** | Entrena las épocas necesarias y conserva la mejor (`best.pt`), sin sobreajuste |
| **Clase «no es hoja de coca»** | La red aprende con cientos de ejemplos negativos (objetos, personas, plantas, mesas vacías) a rechazar lo que no es coca |
| **Detector OOD** (*out-of-distribution*) | Segunda protección: si la foto no se parece a ninguna hoja del entrenamiento, no se fuerza un tipo |
| **TTA** (*test-time augmentation*) | Cada foto se evalúa en 4 orientaciones y se promedia: resultado estable ante el ángulo |
| **5 fotos por análisis** | Votación suave + consistencia entre fotos: una foto mala no decide el resultado |
| **Umbrales calibrados** | «Confirmado» solo cuando la exactitud medida en validación alcanza el objetivo (98 %) |
| **Explicabilidad** | Informe en lenguaje natural y mapas de atención Grad-CAM: se puede ver *por qué* decidió la red |

## Estructura

```
API COCA/
├── app/
│   ├── main.py                 Aplicación FastAPI (inicio, CORS, límites, errores en JSON)
│   ├── rutas.py                Endpoints
│   ├── config.py               Configuración (.env)
│   ├── schemas.py              Formato de las respuestas
│   ├── seguridad.py            Clave X-API-Key
│   └── servicios/
│       ├── clasificador.py     Red neuronal + TTA + detector OOD + mapas de atención (Grad-CAM)
│       ├── analisis.py         Reglas del veredicto (lógica pura, probada)
│       ├── explicacion.py      Informe en lenguaje natural del resultado
│       ├── imagenes.py         Lectura segura y calidad de las fotos
│       ├── catalogo.py         Nombres, descripciones y recomendaciones de cada clase
│       ├── reconocimiento.py   Orquesta todo el análisis
│       └── capturas.py         Guarda fotos para re-entrenar (opcional)
├── notebooks/Entrenamiento_CNN_Hoja_Coca_YOLO26.ipynb   ← entrenamiento en Google Colab
├── weights/                    Modelo entrenado (best.pt, model_meta.json, ood_embeddings.npz)
├── config/clases.json          Textos de cada clase (se crea solo al iniciar)
├── integracion_laravel/        Cambios y guía para SIS-ADEPCOCA
├── tests/                      Pruebas automáticas
├── instalar.bat · iniciar_api.bat · probar_api.bat · ejecutar_pruebas.bat
├── requirements.txt · requirements-dev.txt · .env.example
└── run.py
```

## Puesta en marcha (Windows)

1. **Instalar**: doble clic en `instalar.bat` (requiere Python 3.10+). El entorno virtual se crea en
   `%LOCALAPPDATA%\ApiCoca\venv`, fuera de OneDrive, para no sincronizar ~1.2 GB de librerías.
2. **Entrenar** en Google Colab con el notebook (ver la sección siguiente) y descargar `modelo_coca_api.zip`.
3. **Instalar el modelo**: descomprime `modelo_coca_api.zip` **dentro de esta carpeta** (llena `weights/`).
4. **Iniciar**: doble clic en `iniciar_api.bat` y abre:
   * <http://127.0.0.1:5000/api/status> → debe decir `"modelo_cargado": true`, listar tus clases y
     `"clase_rechazo": ["no_es_coca"]`.
   * <http://127.0.0.1:5000/docs> → documentación interactiva para probar con tus fotos.
5. **Completar los textos** de `config/clases.json` (nombre visible, descripción y recomendación de cada tipo):
   la API los usa para redactar la explicación de cada resultado.
6. **Verificar**: `ejecutar_pruebas.bat` (con el modelo instalado también prueba tu `best.pt` real) y
   `probar_api.bat foto1.jpg foto2.jpg` (guarda los mapas de atención en `resultados_prueba/`).
7. **Conectar con Laravel**: sigue [`integracion_laravel/LEEME.md`](integracion_laravel/LEEME.md).

## Entrenamiento en Google Colab

1. Abre <https://colab.research.google.com> → **Archivo → Subir notebook** →
   `notebooks/Entrenamiento_CNN_Hoja_Coca_YOLO26.ipynb`.
2. **Entorno de ejecución → Cambiar tipo de entorno → GPU T4**.
3. Panel 🔑 **Secretos** → agrega `ROBOFLOW_API_KEY` (Roboflow → Settings → API Keys) y activa el acceso del notebook.
4. En la celda **Configuración** completa `ROBOFLOW_WORKSPACE`, `ROBOFLOW_PROYECTO` y `ROBOFLOW_VERSION`
   (están en Roboflow → Versions → Download Dataset → *Show download code*).
5. **Entorno de ejecución → Ejecutar todas**. Al final se descarga `modelo_coca_api.zip` (y se guarda una copia
   en Google Drive, carpeta `ADEPCOCA_modelo`).

El notebook acepta proyectos de Roboflow de **clasificación** (una carpeta por tipo) y de **detección o
segmentación** (cada hoja etiquetada con una caja se recorta como muestra). Además del entrenamiento:

* Agrega la clase **`no_es_coca`** con imágenes de Caltech-101 (objetos, personas, animales, plantas) y fondos vacíos.
* Muestra la **arquitectura de la CNN capa por capa** (convoluciones, bloques CSP, atención, cabeza de clasificación,
  tamaño de salida y parámetros de cada capa).
* Curvas de pérdida y exactitud **por época**, con la mejor época marcada.
* **Matrices de confusión** (cantidades y porcentajes) en validación y prueba, y las confusiones más frecuentes.
* Reporte por clase (precisión, sensibilidad, F1), exactitud balanceada, Kappa, MCC, top-2/top-3.
* Curvas **ROC** y **Precisión-Recall** por clase, **calibración** de la confianza (ECE).
* Elección de **TTA** y de los **umbrales**, tasa de rechazo de «no es coca», galería de errores,
  **mapas de atención Grad-CAM** por tipo de hoja, **mapas de características** de las capas convolucionales,
  prueba de **robustez** (oscuridad, desenfoque, rotación, ruido, compresión), detector OOD y velocidad de inferencia.

Todo queda en la carpeta `reporte/` del ZIP para tu informe.

### Consejos para un dataset preciso

* **150–300 fotos o más por tipo de hoja**, de hojas distintas (no 50 fotos de la misma hoja).
* Variedad real: distintas luces, fondos, cámaras y orientaciones.
* **Incluye fotos tomadas con la cámara del propio sistema**: es la mejora de precisión más grande.
* Agrega en Roboflow una clase **`no_es_coca`** con fotos reales de tu entorno (otras hojas de los Yungas, manos,
  mesas, bolsas): el notebook la completa con más ejemplos y la red rechazará mejor lo que no es coca.
* Clases balanceadas y etiquetas revisadas (los errores de la galería suelen revelar fotos mal etiquetadas).
* Preprocesamiento en Roboflow: *Auto-Orient* basta; el notebook redimensiona y aumenta los datos.

## Endpoints

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/health` | `200` si el modelo está cargado, `503` con el motivo si no (sin clave) |
| GET | `/api/status` | Estado detallado: modelo, clases, clase de rechazo, umbrales, detector OOD |
| GET | `/api/clases` | Clases con nombre visible, descripción y recomendación |
| POST | `/api/analizar` | **Varias fotos de la misma hoja** (campo `imagenes`, recomendado 5) → análisis combinado |
| POST | `/api/predecir` | Una sola foto (campo `imagen`), compatible con el `CocaClassifierService` original |

```bash
curl -F "imagenes=@foto1.jpg" -F "imagenes=@foto2.jpg" -F "imagenes=@foto3.jpg" \
     -H "X-API-Key: TU_CLAVE" http://127.0.0.1:5000/api/analizar
```

Respuesta (resumida):

```json
{
  "status": "success",
  "estado": "confirmado",
  "es_hoja_de_coca": true,
  "mensaje": "Hoja clasificada como «Primera» con 96.4% de confianza (5 de 5 fotos coinciden).",
  "explicacion": "Analicé las 5 fotos con la red neuronal convolucional. El tipo de hoja más probable es «Primera», con 96.4 % de confianza; la segunda opción, «Segunda», obtuvo 2.1 % (diferencia amplia). 5 de 5 fotos analizadas señalan el mismo tipo. La iluminación fue buena y la nitidez buena. Con esta evidencia, el resultado es confiable.",
  "clase": "Primera",
  "confianza": 0.9641,
  "probabilidades": { "Primera": 0.9641, "Segunda": 0.0213, "No es hoja de coca": 0.0021 },
  "metricas": { "confianza_modelo": 96.4, "consistencia": 100.0, "similitud_dataset": 78.2, "iluminacion": 100.0, "nitidez": 64.0 },
  "por_imagen": [ { "indice": 1, "clase": "Primera", "confianza": 0.95, "mapa_atencion": "data:image/jpeg;base64,..." } ],
  "recomendaciones": ["Apta para comercialización premium."],
  "advertencias": [],
  "tiempo_proceso": 1.42
}
```

Los errores siempre llegan como `{"status": "error", "message": "...", "codigo": "..."}` con el código HTTP
adecuado (`401`, `413`, `422`, `503`).

## Cómo se decide el resultado

Primero **¿es hoja de coca?** y después **¿de qué tipo?**:

| Estado | Regla |
|---|---|
| `rechazado` | la clase «no es hoja de coca» reúne más del 50 % de la probabilidad |
| `confirmado` | confianza promedio ≥ umbral alto **y** al menos 60 % de las fotos coinciden |
| `probable` | confianza ≥ umbral mínimo (o las fotos no coinciden lo suficiente) |
| `no_concluyente` | confianza baja, o más de la mitad de las fotos no se parecen a las hojas del entrenamiento |

Los umbrales salen del notebook (`weights/model_meta.json`) y se pueden fijar en `.env`.

## Explicabilidad: por qué decidió la red

* **Explicación en lenguaje natural** (`explicacion`): la API redacta un informe con las probabilidades, la segunda
  opción y su diferencia, cuántas fotos coinciden, las fotos descartadas, la calidad de luz y nitidez y los rasgos del
  tipo de hoja (de `config/clases.json`). Se genera localmente a partir de lo que midió la red.
* **Mapas de atención Grad-CAM** (`por_imagen[].mapa_atencion`): una imagen por foto donde el rojo marca las zonas que
  más influyeron en la decisión. Si la red mira la hoja (bordes, nervaduras, manchas) y no el fondo, está aprendiendo
  lo correcto. Se desactivan con `MAPAS_ATENCION=false`.

## Configuración (`.env`)

| Variable | Por defecto | Descripción |
|---|---|---|
| `HOST` / `PORT` | `127.0.0.1` / `5000` | Dirección de la API (debe coincidir con `COCA_API_URL` de Laravel) |
| `API_KEY` | vacío | Clave compartida con Laravel (`COCA_API_KEY`). **Obligatoria en producción** |
| `DEVICE` | `cpu` | `auto` usa una GPU NVIDIA si existe |
| `TTA` | del notebook | `true` / `false` |
| `UMBRAL_CONFIANZA_ALTA` / `_MINIMA` | del notebook | Umbrales del veredicto (0–1) |
| `MAPAS_ATENCION` | `true` | Mapas Grad-CAM en cada respuesta |
| `CLASES_RECHAZO` | vacío | Nombre de tu clase «no es coca» si no se llama `no_es_coca`, `otro` o `fondo` |
| `MAX_IMAGENES` / `MAX_MB_POR_IMAGEN` | `10` / `10` | Límites por petición |
| `GUARDAR_CAPTURAS` | `false` | Guarda las fotos analizadas en `data/capturas/` para re-entrenar |

## Pruebas automáticas

`ejecutar_pruebas.bat` ejecuta 52 pruebas que no necesitan el modelo ni internet: reglas del veredicto, rechazo de
«no es hoja de coca», explicación, lectura de imágenes (EXIF, transparencias, archivos dañados, tamaños), endpoints,
compatibilidad con Laravel, límites y clave `X-API-Key`. Con `weights/best.pt` instalado, 2 pruebas más verifican tu
modelo real (análisis y mapas de atención).

## Despliegue en el servidor (Linux)

Probado para Ubuntu 24.04 (Python 3.12). `api-coca-servidor.tar.gz` trae el código, el modelo (`weights/`),
las pruebas y 3 fotos de muestra (`pruebas_servidor/`); no trae el `.env` de tu PC.

```bash
sudo apt update && sudo apt install -y python3-venv libgl1 libglib2.0-0t64     # libGL/glib los necesita OpenCV
sudo tar --no-same-owner -xzf api-coca-servidor.tar.gz -C /var/www            # crea /var/www/api-coca
cd /var/www/api-coca
sudo python3 -m venv .venv
sudo .venv/bin/pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu   # PyTorch sin CUDA (más liviano)
sudo .venv/bin/pip install -r requirements.txt
sudo cp .env.example .env                 # pon API_KEY y deja HOST=127.0.0.1
sudo mkdir -p .config && sudo chown -R www-data:www-data .config              # ajustes de Ultralytics y matplotlib
```

`/etc/systemd/system/api-coca.service`:

```ini
[Unit]
Description=API Reconocimiento de Hoja de Coca (ADEPCOCA)
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/api-coca
Environment=PYTHONUTF8=1
Environment=YOLO_CONFIG_DIR=/var/www/api-coca/.config/Ultralytics
Environment=MPLCONFIGDIR=/var/www/api-coca/.config/matplotlib
ExecStart=/var/www/api-coca/.venv/bin/python run.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now api-coca
curl http://127.0.0.1:5000/api/health
```

La API no se publica en internet: Laravel la llama dentro del mismo servidor. No descarga paquetes ni envía
telemetría en tiempo de ejecución (`YOLO_AUTOINSTALL=false`, `YOLO_OFFLINE=true`).

## Mejorar la precisión con el tiempo

1. Activa `GUARDAR_CAPTURAS=true`: cada análisis queda en `data/capturas/<fecha>/` con sus fotos y su resultado.
2. Revisa las fotos (sobre todo las `probable`, `no_concluyente` y `rechazado`), súbelas a Roboflow con la etiqueta
   correcta (o a `no_es_coca` si no eran hojas).
3. Genera una nueva versión del dataset, vuelve a ejecutar el notebook y reemplaza `weights/`.

## Solución de problemas

| Síntoma | Solución |
|---|---|
| `/api/health` responde 503 «No se encontró el modelo» | Descomprime `modelo_coca_api.zip` en esta carpeta |
| «El modelo es de tipo 'detect'» | La API necesita un modelo de clasificación: usa el `best.pt` del notebook |
| `model_meta.json no corresponde a best.pt` en el log | Copia los 3 archivos de `weights/` del mismo entrenamiento |
| «La API ya está encendida» al iniciar | Ya hay una ventana de la API abierta: no hace falta otra, el módulo ya puede usarla |
| «Otro programa ya usa el puerto 5000» | Cierra ese programa, o cambia `PORT` y también `COCA_API_URL` en Laravel |
| Rechaza hojas de coca reales | Agrega a Roboflow más fotos de hojas en esas condiciones (luz, fondo, cámara) y re-entrena; si persiste, baja `FRACCION_NO_COCA` en el notebook |
| Acepta cosas que no son coca | Agrega esas fotos a la clase `no_es_coca` en Roboflow y re-entrena |
| Respuestas lentas en CPU | `TTA=false`, `MAPAS_ATENCION=false` o entrena con `yolo26m-cls.pt` / `IMGSZ=224` |
