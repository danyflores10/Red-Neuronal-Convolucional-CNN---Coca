<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class CocaClassifierService
{
    protected string $apiUrl;
    protected int $timeout;
    protected string $apiKey;

    /**
     * Las consultas de estado (página principal, health) no deben bloquear la vista
     */
    protected const TIMEOUT_ESTADO = 5;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.coca_api.url') ?: 'http://127.0.0.1:5000', '/');
        $this->timeout = (int) (config('services.coca_api.timeout') ?: 90);
        $this->apiKey = (string) config('services.coca_api.key', '');
    }

    /**
     * Petición base hacia la API (con la clave compartida X-API-Key si está configurada)
     */
    protected function cliente(?int $timeout = null): PendingRequest
    {
        $cliente = Http::acceptJson()->timeout($timeout ?? $this->timeout);

        return $this->apiKey !== '' ? $cliente->withHeaders(['X-API-Key' => $this->apiKey]) : $cliente;
    }

    /**
     * Analizar varias fotos de la misma hoja de coca (análisis combinado; recomendado: 5 fotos)
     *
     * @param  UploadedFile[]  $imagenes
     */
    public function analizarImagenes(array $imagenes): array
    {
        try {
            $peticion = $this->cliente();
            foreach (array_values($imagenes) as $i => $imagen) {
                $peticion->attach(
                    'imagenes',
                    file_get_contents($imagen->getRealPath()),
                    $imagen->getClientOriginalName() ?: 'captura_' . ($i + 1) . '.jpg'
                );
            }

            return $this->procesarRespuesta($peticion->post("{$this->apiUrl}/api/analizar"));

        } catch (ConnectionException $e) {
            Log::error('CocaClassifier: Error de conexión', ['error' => $e->getMessage()]);
            return $this->servicioInactivo();
        } catch (Exception $e) {
            Log::error('CocaClassifier: Error general', ['error' => $e->getMessage()]);
            return [
                'status' => 'error',
                'message' => 'Error procesando las imágenes: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Clasificar una imagen de hoja de coca
     */
    public function clasificarImagen(UploadedFile $imagen): array
    {
        try {
            $response = $this->cliente()
                ->attach(
                    'imagen',
                    file_get_contents($imagen->getRealPath()),
                    $imagen->getClientOriginalName()
                )
                ->post("{$this->apiUrl}/api/predecir");

            return $this->procesarRespuesta($response);

        } catch (ConnectionException $e) {
            Log::error('CocaClassifier: Error de conexión', ['error' => $e->getMessage()]);
            return $this->servicioInactivo();
        } catch (Exception $e) {
            Log::error('CocaClassifier: Error general', ['error' => $e->getMessage()]);
            return [
                'status' => 'error',
                'message' => 'Error procesando la imagen: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener las clases disponibles para clasificación
     */
    public function obtenerClases(): array
    {
        try {
            $response = $this->cliente(self::TIMEOUT_ESTADO)->get("{$this->apiUrl}/api/clases");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'status' => 'error',
                'clases' => [],
                'message' => $response->json('message') ?? 'No se pudieron obtener las clases',
            ];

        } catch (Exception $e) {
            Log::warning('CocaClassifier: Error obteniendo clases', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'clases' => [], 'message' => $e->getMessage()];
        }
    }

    /**
     * Verificar el estado completo del servicio
     */
    public function verificarEstado(): array
    {
        try {
            $response = $this->cliente(self::TIMEOUT_ESTADO)->get("{$this->apiUrl}/api/status");

            if ($response->successful()) {
                $data = $response->json();
                $data['servidor'] = 'activo';
                return $data;
            }

            return [
                'status' => 'error',
                'servidor' => 'inactivo',
                'message' => $response->json('message') ?? 'El servicio no respondió correctamente',
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'servidor' => 'inactivo',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verificar rápidamente si la API está activa (y con el modelo cargado)
     */
    public function estaActivo(): bool
    {
        try {
            return $this->cliente(self::TIMEOUT_ESTADO)->get("{$this->apiUrl}/api/health")->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtener la URL base de la API
     */
    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Resultado exitoso normalizado, o el error que explicó la API
     */
    protected function procesarRespuesta(Response $response): array
    {
        $data = $response->json();

        if ($response->successful() && is_array($data)) {
            return $this->normalizarResultado($data);
        }

        return [
            'status' => 'error',
            'codigo' => $data['codigo'] ?? 'http_' . $response->status(),
            'message' => $data['message'] ?? 'Error en la clasificación (código ' . $response->status() . ')',
            'http_status' => $response->status(),
        ];
    }

    protected function servicioInactivo(): array
    {
        return [
            'status' => 'error',
            'message' => 'No se pudo conectar con el servicio de reconocimiento. Verifique que la API esté activa.',
            'servidor' => 'inactivo',
        ];
    }

    /**
     * Normalizar el resultado de la API para la UI
     */
    protected function normalizarResultado(array $data): array
    {
        // Si ya tiene status success, normalizar campos para la UI
        if (isset($data['status']) && $data['status'] === 'success') {
            // Normalizar confianza (0-1 a 0-100 si es necesario)
            if (isset($data['confianza']) && is_numeric($data['confianza'])) {
                $confianza = (float) $data['confianza'];
                if ($confianza > 0 && $confianza <= 1) {
                    $data['confianza'] = round($confianza * 100, 1);
                } else {
                    $data['confianza'] = round($confianza, 1);
                }
            }

            // Asegurar campos mínimos
            $data['clase'] = $data['clase'] ?? $data['tipo'] ?? 'No identificado';
            $data['tipo'] = $data['tipo'] ?? $data['clase'] ?? 'No identificado';
            $data['descripcion'] = $data['descripcion'] ?? '';
            $data['recomendacion'] = $data['recomendacion'] ?? '';

            // Si hay probabilidades, formatearlas
            if (isset($data['probabilidades']) && is_array($data['probabilidades'])) {
                foreach ($data['probabilidades'] as $key => $value) {
                    if (is_numeric($value)) {
                        $val = (float) $value;
                        $data['probabilidades'][$key] = $val <= 1 ? round($val * 100, 1) : round($val, 1);
                    }
                }
            }
        }

        return $data;
    }
}
