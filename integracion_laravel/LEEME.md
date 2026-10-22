# Integración con SIS-ADEPCOCA — módulo «Reconocimiento de Coca»

Con estos cambios, la pantalla `/reconocimiento-coca` deja de hacer el análisis «preliminar» en el navegador y envía
las **5 fotos reales** a la API de tu red neuronal. Luego muestra el resultado oficial: tipo de hoja (o «no es hoja de
coca»), estado, confianza, la **explicación de la red**, métricas, probabilidades, resultado por foto, **mapas de
atención** (dónde se fijó la red) y recomendaciones. Todo lo calcula tu propia red: no se usa ningún servicio de IA
externo.

```
Navegador (cámara, 5 fotos) ──► Laravel  POST /reconocimiento-coca/analizar   (sesión, CSRF, rol Negociante)
                                   └──► API COCA  POST http://127.0.0.1:5000/api/analizar   (X-API-Key)
                                            └──► YOLO26-cls + TTA + «no es hoja de coca» + detector OOD + Grad-CAM
```

La API **ya es compatible** con el código actual de Laravel (`/api/predecir`, `/api/clases`, `/api/status`,
`/api/health`). Estos cambios agregan el análisis combinado de las 5 fotos.

## Archivos que cambian

| Archivo de SIS-ADEPCOCA | Cambio |
|---|---|
| `app/Services/CocaClassifierService.php` | Nuevo `analizarImagenes()` (5 fotos → `/api/analizar`), envía `X-API-Key`, tiempos de espera cortos para las consultas de estado y mensajes de error de la API |
| `app/Http/Controllers/CocaReconocimientoController.php` | `analizar()` acepta de 1 a 5 fotos (`imagenes[]`), las valida y devuelve el resultado completo. Se quitó el «modo demo» que mostraba un resultado falso cuando la API no respondía |
| `resources/views/pages/coca-reconocimiento/index.blade.php` | Envía las fotos recortadas al marco de escaneo y dibuja el resultado real, la explicación de la red y los mapas de atención (que se amplían al tocarlos). Todos los textos se insertan como texto: sin riesgo de XSS |
| `config/services.php` | Agrega `key` y valores por defecto en `coca_api` |

La ruta `POST reconocimiento-coca/analizar` (rol 4) ya existe: **no hay que tocar `routes/`**.

---

## Paso 0 — Antes de empezar

1. La API debe estar funcionando **con el modelo cargado**: abre <http://127.0.0.1:5000/api/status> y verifica
   `"modelo_cargado": true` (ver `README.md` de la API).
2. Guarda el estado actual de SIS-ADEPCOCA para poder volver atrás:
   ```powershell
   cd "C:\Users\hp pavilion\OneDrive\Escritorio\SIS-ADEPCOCA"
   git add -A
   git commit -m "Antes de integrar la API de reconocimiento de coca"
   ```

## Paso 1 — Aplicar los cambios (elige UNA opción)

### Opción A — con git (recomendada, un solo comando)

```powershell
cd "C:\Users\hp pavilion\OneDrive\Escritorio\SIS-ADEPCOCA"
git apply --check "..\API COCA\integracion_laravel\cambios.diff"
git apply "..\API COCA\integracion_laravel\cambios.diff"
```

El primer comando solo verifica (no cambia nada). Si no muestra errores, el segundo aplica los 4 cambios.
Puedes revisar exactamente qué cambia abriendo `cambios.diff` en VS Code.

### Opción B — copiando archivos

1. Copia estos 3 archivos de `API COCA\integracion_laravel\` a la misma ruta dentro de `SIS-ADEPCOCA\`
   (reemplazando los existentes):
   * `app\Services\CocaClassifierService.php`
   * `app\Http\Controllers\CocaReconocimientoController.php`
   * `resources\views\pages\coca-reconocimiento\index.blade.php`
2. En `SIS-ADEPCOCA\config\services.php` reemplaza el bloque `coca_api` por:
   ```php
   'coca_api' => [
       'url' => env('COCA_API_URL', 'http://127.0.0.1:5000'),
       'timeout' => env('COCA_API_TIMEOUT', 90),
       // Clave compartida con la API (API_KEY en el .env de API COCA)
       'key' => env('COCA_API_KEY', ''),
   ],
   ```

## Paso 2 — Variables en el `.env` de SIS-ADEPCOCA

```env
COCA_API_URL=http://127.0.0.1:5000
COCA_API_TIMEOUT=90
COCA_API_KEY=
```

La red responde en 1–3 s en una PC normal; `COCA_API_TIMEOUT=90` da margen de sobra en computadoras lentas.

## Paso 3 — Limpiar la caché de Laravel

```powershell
php artisan config:clear
php artisan view:clear
```

## Paso 4 — Probar la conexión

1. Inicia la API: doble clic en `API COCA\iniciar_api.bat`.
2. Inicia Laravel como siempre (`php artisan serve`).
3. Verificación rápida en el navegador: <http://127.0.0.1:8000/api/coca/health> debe responder
   `{"laravel":"ok","clasificador":"ok", ...}`.
4. Inicia sesión con un usuario **Negociante** y abre **Reconocimiento de Coca**. Arriba a la derecha debe decir
   **«Red neuronal YOLO26 · IA activa»** (si dice «Servicio de IA no disponible», la API está apagada o sin modelo).
5. Enciende la cámara, toma las 5 fotos de la misma hoja y pulsa **Analizar 5 fotos**.

| Estado que verás | Significado |
|---|---|
| **Confirmado** (verde) | Alta confianza y la mayoría de las fotos coinciden |
| **Probable** (amarillo) | Confianza media o fotos que no coinciden del todo: conviene repetir las fotos |
| **No concluyente** (gris) | La red no está segura o las fotos no se parecen a las del entrenamiento |
| **No es hoja de coca** (rojo) | La red neuronal reconoció que en las fotos no hay una hoja de coca (otra cosa, mesa vacía, etc.) |

Debajo del resultado verás la **Explicación de la red neuronal** (el porqué del resultado, en lenguaje natural) y
**Dónde se fijó la red neuronal**: un mapa por foto con las zonas que más influyeron (en rojo).

---

## (Recomendado) Proteger la API con una clave

1. Genera una clave:
   ```powershell
   python -c "import secrets; print(secrets.token_urlsafe(32))"
   ```
2. Pégala en **ambos** `.env`:
   * `API COCA\.env` → `API_KEY=la-clave`
   * `SIS-ADEPCOCA\.env` → `COCA_API_KEY=la-clave`
3. Reinicia la API y ejecuta `php artisan config:clear`.

Sin clave cualquiera que llegue al puerto 5000 podría usar la API. Por defecto la API solo escucha en
`127.0.0.1` (la misma computadora), así que en desarrollo no es un riesgo; **en el servidor sí ponla**.

## (Recomendado) Limitar las rutas públicas `/api/coca/*` de Laravel

En `routes/api.php`, el grupo `coca` (`/api/coca/clasificar`, `/clases`, `/estado`, `/health`) **no exige
inicio de sesión**: en producción cualquiera podría enviar fotos sin parar y sobrecargar el servidor.
La pantalla del sistema no usa esas rutas (usa `/reconocimiento-coca/analizar`, que sí exige sesión y rol). Si
ninguna otra aplicación las usa, lo más seguro es eliminarlas. Si las necesitas, al menos limita la frecuencia:

```php
// routes/api.php
Route::prefix('coca')->middleware('throttle:10,1')->group(function () {
    // ... las mismas 4 rutas
});
```

---

## Producción (servidor Linux con nginx + Cloudflare)

* **nginx**: 5 fotos pesan ~1 MB y el límite por defecto de nginx es 1 MB. En el `server { }` del sistema:
  `client_max_body_size 20M;` y luego `sudo nginx -t && sudo systemctl reload nginx`.
* **PHP**: `upload_max_filesize = 10M`, `post_max_size = 60M`, `max_file_uploads = 20` (o más).
* **API**: ejecútala como servicio (ver «Despliegue en Linux» en el `README.md` de la API), escuchando solo en
  `127.0.0.1:5000` y **con `API_KEY`**. Nunca la publiques directamente en Cloudflare: Laravel es quien la llama.

## Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| Badge «Servicio de IA no disponible» | La API está apagada o sin `weights/best.pt` | Ejecuta `iniciar_api.bat` y revisa <http://127.0.0.1:5000/api/status> |
| «No se pudo conectar con el servicio de reconocimiento» | `COCA_API_URL` incorrecta o API apagada | Revisa la URL y el puerto; `php artisan config:clear` |
| «Falta el encabezado X-API-Key o la clave no es válida» | `API_KEY` y `COCA_API_KEY` no coinciden | Pon la misma clave en ambos `.env`, reinicia la API y `php artisan config:clear` |
| «Tu sesión expiró» | Token CSRF vencido (página abierta mucho tiempo) | Recarga la página |
| Error 413 en producción | Límite de nginx | `client_max_body_size 20M;` |
| Casi todo sale «No concluyente» | Las fotos del sistema son muy distintas a las del dataset | Activa `GUARDAR_CAPTURAS=true` en la API, sube esas fotos etiquetadas a Roboflow y vuelve a entrenar |
| La vista sigue mostrando «IA próximamente» | Caché de vistas | `php artisan view:clear` y recarga con Ctrl+F5 |

## Cómo deshacer los cambios

```powershell
cd "C:\Users\hp pavilion\OneDrive\Escritorio\SIS-ADEPCOCA"
git apply -R "..\API COCA\integracion_laravel\cambios.diff"
```

Esto revierte exactamente los 4 cambios de este parche y nada más.
