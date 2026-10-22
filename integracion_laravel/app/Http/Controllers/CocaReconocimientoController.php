<?php

namespace App\Http\Controllers;

use App\Services\CocaClassifierService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class CocaReconocimientoController extends Controller
{
    /**
     * Fotos de la misma hoja que se envían en cada análisis (la vista toma 5)
     */
    protected const MAX_FOTOS = 5;

    protected CocaClassifierService $classifier;

    public function __construct(CocaClassifierService $classifier)
    {
        $this->classifier = $classifier;
    }

    /**
     * Mostrar la vista principal de reconocimiento de coca
     */
    public function index(): View
    {
        $estado = $this->classifier->verificarEstado();
        $apiActiva = ($estado['servidor'] ?? 'inactivo') === 'activo';
        // Con la API apagada no se pide nada más: en Windows cada intento fallido tarda ~2 s
        $clases = $apiActiva ? $this->classifier->obtenerClases() : [];

        return view('pages.coca-reconocimiento.index', [
            'clases' => $clases['clases'] ?? [],
            'descripciones' => $clases['descripcion'] ?? [],
            'apiActiva' => $apiActiva,
            'estadoApi' => $estado,
        ]);
    }

    /**
     * Analizar las fotos de la hoja mediante la API de reconocimiento (AJAX)
     */
    public function analizar(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'imagenes' => ['required_without:imagen', 'array', 'min:1', 'max:' . self::MAX_FOTOS],
            'imagenes.*' => ['bail', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'], // Max 10MB por foto
            'imagen' => ['bail', 'required_without:imagenes', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
        ], [
            'imagenes.required_without' => 'Toma al menos una foto de la hoja para analizar.',
            'imagenes.array' => 'Las fotos no llegaron en el formato esperado.',
            'imagenes.max' => 'Puedes enviar como máximo :max fotos por análisis.',
            'imagenes.*.image' => 'Una de las capturas no es una imagen válida.',
            'imagenes.*.mimes' => 'Solo se permiten imágenes JPG, PNG o WEBP.',
            'imagenes.*.max' => 'Cada foto debe pesar menos de 10MB.',
            'imagen.required_without' => 'Debes seleccionar una imagen para analizar.',
            'imagen.image' => 'El archivo debe ser una imagen válida.',
            'imagen.mimes' => 'Solo se permiten imágenes JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no debe superar los 10MB.',
        ]);

        // Un solo mensaje claro para la vista, sin el "(and 1 more error)" que Laravel agrega en inglés
        if ($validador->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validador->errors()->first(),
                'errors' => $validador->errors(),
            ], 422);
        }

        $fotos = $request->file('imagenes') ?: [$request->file('imagen')];
        $resultado = $this->classifier->analizarImagenes($fotos);

        if (($resultado['status'] ?? null) === 'success') {
            return response()->json([
                'success' => true,
                'resultado' => $this->adaptarResultadoParaUI($resultado),
            ]);
        }

        // Servicio caído o sin modelo cargado: 503. Foto rechazada por la API: 422. Otro fallo: 502.
        $httpApi = (int) ($resultado['http_status'] ?? 0);
        $codigo = match (true) {
            ($resultado['servidor'] ?? null) === 'inactivo', $httpApi === 503 => 503,
            in_array($httpApi, [413, 422], true) => 422,
            default => 502,
        };

        return response()->json([
            'success' => false,
            'message' => $resultado['message'] ?? 'No se pudo analizar la hoja. Intenta nuevamente.',
        ], $codigo);
    }

    /**
     * API: Clasificar imagen (para consumo externo)
     */
    public function apiClasificar(Request $request): JsonResponse
    {
        $request->validate([
            'imagen' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
        ]);

        return response()->json($this->classifier->clasificarImagen($request->file('imagen')));
    }

    /**
     * API: Obtener clases disponibles
     */
    public function apiClases(): JsonResponse
    {
        return response()->json($this->classifier->obtenerClases());
    }

    /**
     * API: Verificar estado del servicio
     */
    public function apiEstado(): JsonResponse
    {
        return response()->json($this->classifier->verificarEstado());
    }

    /**
     * API: Health check rápido
     */
    public function apiHealth(): JsonResponse
    {
        return response()->json([
            'laravel' => 'ok',
            'clasificador' => $this->classifier->estaActivo() ? 'ok' : 'offline',
            'api_url' => $this->classifier->getApiUrl(),
        ]);
    }

    /**
     * Adaptar el resultado de la API al formato que dibuja la vista
     */
    protected function adaptarResultadoParaUI(array $resultado): array
    {
        // Valores 0-1 de la API a porcentaje (null si la API no los envía)
        $porcentaje = fn ($valor) => is_numeric($valor) ? round(((float) $valor) * 100, 1) : null;

        $porFoto = array_map(function (array $foto) use ($porcentaje) {
            $mapa = $foto['mapa_atencion'] ?? null;
            $calidad = is_array($foto['calidad'] ?? null) ? $foto['calidad'] : [];

            return [
                'indice' => $foto['indice'] ?? null,
                'clase' => $foto['clase'] ?? '',
                'confianza' => $porcentaje($foto['confianza'] ?? 0),
                'coincide' => (bool) ($foto['coincide_con_resultado'] ?? false),
                'es_hoja_de_coca' => (bool) ($foto['es_hoja_de_coca'] ?? true),
                'fuera_de_distribucion' => (bool) ($foto['fuera_de_distribucion'] ?? false),
                // Las 3 clases más probables de esta foto, en %
                'top' => array_map(fn ($t) => [
                    'nombre' => (string) ($t['nombre'] ?? ''),
                    'probabilidad' => $porcentaje($t['probabilidad'] ?? 0),
                ], array_slice(array_filter($foto['top'] ?? [], 'is_array'), 0, 3)),
                'similitud' => $porcentaje($foto['similitud_dataset'] ?? null),
                'iluminacion' => $porcentaje($calidad['iluminacion'] ?? null),
                'nitidez' => $porcentaje($calidad['nitidez'] ?? null),
                'advertencias' => array_values(array_filter($calidad['advertencias'] ?? [], 'is_string')),
                // Mapa de atención (Grad-CAM) de la red: solo se acepta una imagen JPEG embebida
                'mapa_atencion' => is_string($mapa) && str_starts_with($mapa, 'data:image/jpeg;base64,') ? $mapa : null,
            ];
        }, array_values(array_filter($resultado['por_imagen'] ?? [], 'is_array')));

        return [
            'estado' => $resultado['estado'] ?? 'no_concluyente',
            'es_hoja_de_coca' => (bool) ($resultado['es_hoja_de_coca'] ?? true),
            'mensaje' => $resultado['mensaje'] ?? '',
            'explicacion' => $resultado['explicacion'] ?? '',
            'tipo' => $resultado['clase'] ?? $resultado['tipo'] ?? 'No identificado',
            'descripcion' => $resultado['descripcion'] ?? '',
            'confianza' => (float) ($resultado['confianza'] ?? 0),
            'probabilidades' => $resultado['probabilidades'] ?? [],
            'metricas' => $resultado['metricas'] ?? [],
            'por_imagen' => $porFoto,
            'recomendaciones' => $resultado['recomendaciones'] ?? [],
            'advertencias' => $resultado['advertencias'] ?? [],
            'imagenes_analizadas' => $resultado['imagenes_analizadas'] ?? count($porFoto),
            'tiempo_proceso' => $resultado['tiempo_proceso'] ?? null,
            'modelo' => [
                'arquitectura' => $resultado['modelo']['arquitectura'] ?? null,
                'imgsz' => $resultado['modelo']['imgsz'] ?? null,
            ],
        ];
    }
}
