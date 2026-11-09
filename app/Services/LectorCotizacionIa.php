<?php

namespace App\Services;

use App\Models\User;
use App\Services\Asistente\ModeloLenguaje;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Leer con IA": manda el PDF de una cotización a Claude y recibe sus datos
 * en un JSON con esquema fijo. Solo PROPONE: el formulario se rellena y la
 * persona revisa y corrige antes de guardar; la IA no toca partidas ni
 * precios. Sin ANTHROPIC_API_KEY queda apagada.
 */
final class LectorCotizacionIa
{
    /** Límite de la API para un PDF en la petición. */
    private const MAX_BYTES = 30 * 1024 * 1024;

    private const MAX_TOKENS = 16000;

    private const INSTRUCCIONES = <<<'TXT'
        Eres un capturista del Tren Maya (Zona Oriente). Lee la cotización de proveedor adjunta y devuelve sus datos tal como aparecen.
        - "proveedor": razón social o nombre del emisor. "folio": número o folio de la cotización.
        - "fecha": fecha de emisión en formato AAAA-MM-DD; vacío si no aparece.
        - "subtotal", "iva" y "total": importes como números sin símbolo ni comas (por ejemplo 1234.50); vacío si no aparecen.
        - "renglones": un elemento por cada partida cotizada, con "clave" (código o SKU; vacío si no hay), "descripcion", "cantidad", "precio_unitario" e "importe" como números sin símbolo ni comas.
        - Copia los valores textualmente; no los corrijas, no recalcules importes ni inventes datos. Si un dato no está, déjalo vacío.
        TXT;

    public function __construct(private readonly ModeloLenguaje $modelo) {}

    public function disponible(): bool
    {
        return $this->modelo->disponible();
    }

    /**
     * @return array{proveedor: string, folio: string, fecha: string|null, subtotal: float|null, iva: float|null, total: float|null, renglones: list<array{clave: string|null, descripcion: string, cantidad: float|null, precio_unitario: float|null, importe: float|null}>}
     */
    public function leer(UploadedFile $pdf, User $usuario): array
    {
        if (! $this->disponible()) {
            throw ValidationException::withMessages(['ia' => __('La IA no está configurada (falta ANTHROPIC_API_KEY).')]);
        }

        if ($pdf->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['ia' => __('El PDF es demasiado grande para la IA (máximo 30 MB).')]);
        }

        $respuesta = $this->modelo->transmitir([
            'maxTokens' => self::MAX_TOKENS,
            'system' => self::INSTRUCCIONES,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($pdf->getRealPath()))]],
                ['type' => 'text', 'text' => 'Extrae los datos de esta cotización.'],
            ]]],
            'outputConfig' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
        ]);

        activity('cotizacion')->causedBy($usuario)->event('lectura_ia')->withProperties([
            'modelo' => $respuesta->model,
            'stop_reason' => $respuesta->stopReason,
            'tokens_entrada' => $respuesta->usage->inputTokens,
            'tokens_salida' => $respuesta->usage->outputTokens,
        ])->log('Cotización enviada a la IA para leerla.');

        return $this->interpretar($respuesta->stopReason, $respuesta->content);
    }

    /**
     * @param  array<int, object>  $bloques
     * @return array<string, mixed>
     */
    private function interpretar(?string $motivo, array $bloques): array
    {
        if ($motivo === 'refusal') {
            throw ValidationException::withMessages(['ia' => __('La IA no procesó este documento. Captura la cotización manualmente.')]);
        }

        if ($motivo === 'max_tokens') {
            throw ValidationException::withMessages(['ia' => __('La cotización es demasiado larga para leerla de una sola vez. Captúrala manualmente.')]);
        }

        $json = collect($bloques)->first(fn (object $b) => ($b->type ?? null) === 'text')?->text;
        $datos = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($datos) || ! isset($datos['renglones']) || ! is_array($datos['renglones'])) {
            Log::warning('Respuesta de la IA sin el formato esperado al leer una cotización.', ['motivo' => $motivo]);

            throw ValidationException::withMessages(['ia' => __('La IA devolvió una respuesta que no se pudo interpretar. Intenta de nuevo o captúrala manualmente.')]);
        }

        $renglones = [];

        foreach (array_slice($datos['renglones'], 0, 200) as $fila) {
            $descripcion = mb_substr(trim((string) ($fila['descripcion'] ?? '')), 0, 255);

            if ($descripcion === '') {
                continue;
            }

            $renglones[] = [
                'clave' => ($clave = mb_substr(trim((string) ($fila['clave'] ?? '')), 0, 50)) === '' ? null : $clave,
                'descripcion' => $descripcion,
                'cantidad' => $this->numero($fila['cantidad'] ?? null),
                'precio_unitario' => $this->numero($fila['precio_unitario'] ?? null),
                'importe' => $this->numero($fila['importe'] ?? null),
            ];
        }

        $fecha = trim((string) ($datos['fecha'] ?? ''));

        return [
            'proveedor' => mb_substr(trim((string) ($datos['proveedor'] ?? '')), 0, 150),
            'folio' => mb_substr(trim((string) ($datos['folio'] ?? '')), 0, 50),
            'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : null,
            'subtotal' => $this->numero($datos['subtotal'] ?? null),
            'iva' => $this->numero($datos['iva'] ?? null),
            'total' => $this->numero($datos['total'] ?? null),
            'renglones' => $renglones,
        ];
    }

    private function numero(mixed $valor): ?float
    {
        $texto = preg_replace('/[^0-9.\-]/', '', (string) $valor);

        return $texto === '' || ! is_numeric($texto) ? null : round((float) $texto, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $texto = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'proveedor' => $texto,
                'folio' => $texto,
                'fecha' => $texto,
                'subtotal' => $texto,
                'iva' => $texto,
                'total' => $texto,
                'renglones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'clave' => $texto,
                            'descripcion' => $texto,
                            'cantidad' => $texto,
                            'precio_unitario' => $texto,
                            'importe' => $texto,
                        ],
                        'required' => ['clave', 'descripcion', 'cantidad', 'precio_unitario', 'importe'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['proveedor', 'folio', 'fecha', 'subtotal', 'iva', 'total', 'renglones'],
            'additionalProperties' => false,
        ];
    }
}
