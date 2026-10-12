<?php

namespace App\Services\Secciones;

use App\Models\SeccionArchivo;
use App\Models\User;
use App\Services\Asistente\ModeloLenguaje;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * "Extraer con IA": manda un documento que no se pudo leer sin IA (PDF
 * escaneado, foto, Word sin tablas reales) a Claude y recibe sus tablas
 * en un JSON con esquema fijo (salidas estructuradas). El resultado entra
 * a la misma vista previa que cualquier otra carga: nada se guarda en la
 * tabla sin que el usuario lo revise.
 */
final class ExtractorIa
{
    /** Límites de la API: 32 MB por petición (PDF) y ~5 MB por imagen. */
    private const MAX_PDF_BYTES = 30 * 1024 * 1024;

    private const MAX_IMAGEN_BYTES = 5 * 1024 * 1024;

    private const MAX_TOKENS = 64000;

    private const MIME_IMAGEN = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    private const INSTRUCCIONES = <<<'TXT'
        Eres un capturista del Tren Maya (Zona Oriente). Extrae TODAS las tablas del documento adjunto tal como aparecen.
        - Una entrada en "tablas" por cada tabla distinta; "nombre" describe brevemente la tabla.
        - "encabezados": los títulos de columna exactamente como vienen. Si la tabla no trae títulos, propón unos cortos y claros.
        - "filas": cada renglón de datos, con una celda por encabezado y en el mismo orden. Celda vacía = "".
        - Copia los valores textualmente (nombres, números, fechas); no los resumas, traduzcas ni corrijas.
        - Si el documento no contiene ninguna tabla, pero sí datos estructurados (listas, formularios), organízalos en una tabla.
        - Si no hay datos que tabular, devuelve "tablas": [].
        TXT;

    public function __construct(
        private readonly ModeloLenguaje $modelo,
        private readonly ArchivoService $archivos,
    ) {}

    /**
     * @return list<array{nombre: string, filas: list<list<string|null>>}>
     */
    public function extraerTablas(SeccionArchivo $archivo, User $usuario): array
    {
        if (! $this->modelo->disponible()) {
            throw ValidationException::withMessages(['ia' => __('La IA no está configurada (falta ANTHROPIC_API_KEY).')]);
        }

        $respuesta = $this->modelo->transmitir([
            'maxTokens' => self::MAX_TOKENS,
            'system' => self::INSTRUCCIONES,
            'messages' => [['role' => 'user', 'content' => [
                ...$this->contenido($archivo),
                ['type' => 'text', 'text' => 'Extrae las tablas de este documento.'],
            ]]],
            'outputConfig' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
        ]);

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($archivo)
            ->event('extraccion_ia')
            ->withProperties([
                'modelo' => $respuesta->model,
                'stop_reason' => $respuesta->stopReason,
                'tokens_entrada' => $respuesta->usage->inputTokens,
                'tokens_salida' => $respuesta->usage->outputTokens,
            ])
            ->log('Documento enviado a la IA para extraer tablas.');

        return $this->interpretar($respuesta->stopReason, $respuesta->content);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contenido(SeccionArchivo $archivo): array
    {
        $extension = $archivo->extension;

        if ($extension === 'pdf' && $archivo->tamano <= self::MAX_PDF_BYTES) {
            return [['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $this->base64($archivo)]]];
        }

        if (isset(self::MIME_IMAGEN[$extension]) && $archivo->tamano <= self::MAX_IMAGEN_BYTES) {
            return [['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => self::MIME_IMAGEN[$extension], 'data' => $this->base64($archivo)]]];
        }

        $texto = $archivo->texto_extraido;

        if ($texto === null || trim($texto) === '') {
            throw ValidationException::withMessages(['ia' => __('Este archivo es demasiado grande o no tiene texto legible para enviarlo a la IA (PDF máx. 30 MB, imagen máx. 5 MB).')]);
        }

        return [['type' => 'text', 'text' => '<documento nombre="'.e($archivo->nombre_original)."\">\n{$texto}\n</documento>"]];
    }

    /**
     * @param  array<int, object>  $bloques
     * @return list<array{nombre: string, filas: list<list<string|null>>}>
     */
    private function interpretar(?string $motivo, array $bloques): array
    {
        if ($motivo === 'refusal') {
            throw ValidationException::withMessages(['ia' => __('La IA no procesó este documento. Captura la información manualmente o intenta con otro archivo.')]);
        }

        if ($motivo === 'max_tokens') {
            throw ValidationException::withMessages(['ia' => __('El documento es demasiado largo para extraerlo de una sola vez. Divídelo en partes más pequeñas.')]);
        }

        $json = collect($bloques)->first(fn (object $bloque) => ($bloque->type ?? null) === 'text')?->text;
        $datos = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($datos) || ! isset($datos['tablas']) || ! is_array($datos['tablas'])) {
            Log::warning('Respuesta de la IA sin el formato esperado al extraer tablas.', ['motivo' => $motivo]);

            throw ValidationException::withMessages(['ia' => __('La IA devolvió una respuesta que no se pudo interpretar. Intenta de nuevo.')]);
        }

        $tablas = [];

        foreach ($datos['tablas'] as $tabla) {
            $encabezados = array_map('strval', $tabla['encabezados'] ?? []);
            $filas = array_map(
                fn ($fila) => array_map(fn ($celda) => trim((string) $celda) === '' ? null : trim((string) $celda), (array) $fila),
                $tabla['filas'] ?? [],
            );

            if ($encabezados !== [] && $filas !== []) {
                $tablas[] = ['nombre' => mb_substr((string) ($tabla['nombre'] ?? 'Tabla'), 0, 120), 'filas' => [$encabezados, ...$filas]];
            }
        }

        if ($tablas === []) {
            throw ValidationException::withMessages(['ia' => __('La IA no encontró tablas ni datos que organizar en este documento.')]);
        }

        return $tablas;
    }

    /**
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tablas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre' => ['type' => 'string'],
                            'encabezados' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'filas' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                        'required' => ['nombre', 'encabezados', 'filas'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tablas'],
            'additionalProperties' => false,
        ];
    }

    private function base64(SeccionArchivo $archivo): string
    {
        return base64_encode((string) Storage::disk($this->archivos->disco())->get($archivo->ruta));
    }
}
