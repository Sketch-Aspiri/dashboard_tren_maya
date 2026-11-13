<?php

namespace App\Services\Ecosistema;

use App\Enums\EstatusAsistencia;
use App\Models\Empleado;
use App\Services\AsistenciaCapturaService;
use App\Services\Asistente\CatalogoAsistente;
use App\Services\Asistente\HerramientasAsistente;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Herramientas para IA que el MCP de Tickets publica por el puente. Son las cuatro consultas del asistente del
 * Dashboard (solo lectura, con los datos personales redactados), el catálogo de secciones, la lista de personal sin
 * asistencia de hoy y la captura de asistencia de hoy (la única que escribe, ver CapturaAsistenciaIa).
 *
 * Ninguna usa sesión: el puente autentica al SISTEMA que llama y es Tickets quien decide qué cliente MCP puede usar
 * cada herramienta (una de lectura o una de escritura, marcada con `escritura`).
 */
final class IaHerramientas
{
    private const MAX_PENDIENTES = 100;

    public function __construct(
        private readonly CatalogoAsistente $catalogo,
        private readonly AsistenciaCapturaService $asistencia,
        private readonly CapturaAsistenciaIa $captura,
    ) {}

    /**
     * Nombre, descripción, JSON Schema de la entrada y si escribe.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, escritura: bool}>
     */
    public function definiciones(): array
    {
        $consultas = array_map(function (array $definicion): array {
            unset($definicion['strict']);

            return $definicion + ['escritura' => false];
        }, app(HerramientasAsistente::class)->definiciones());

        return [
            ...$consultas,
            [
                'name' => 'catalogo_dashboard',
                'description' => 'Fecha de hoy, estaciones, estatus de personal y catálogo de secciones del Dashboard (slug, grupo, registros y columnas). Úsalo ANTES de consultar_seccion para conocer los slugs y nombres de columna.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false],
                'escritura' => false,
            ],
            [
                'name' => 'asistencia_pendiente_hoy',
                'description' => 'Asistencia de HOY (hora de Cancún): sin estación, el avance de cada estación operativa; con una estación, la lista del personal activo que aún no tiene asistencia (número de empleado, nombre, puesto). Úsalo antes de capturar_asistencia.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['estacion' => ['type' => ['string', 'null'], 'description' => 'Nombre o código de la estación; omítelo para ver todas.']],
                    'additionalProperties' => false,
                ],
                'escritura' => false,
            ],
            [
                'name' => 'capturar_asistencia',
                'description' => 'Captura la asistencia de HOY de una estación para los empleados indicados (por número de empleado). Solo hoy y solo personal activo de esa estación. NUNCA modifica un registro que ya existe: quien ya tiene asistencia se informa como omitido. Las filas inválidas se rechazan una por una y se informan (número de fila desde 1). Nunca captures por suposición: usa solo lo que la fuente dice con claridad.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'estacion' => ['type' => 'string', 'description' => 'Nombre o código de una estación operativa.'],
                        'registros' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => (int) config('ecosistema.ia.max_registros'),
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'no_empleado' => ['type' => 'string', 'description' => 'Número de empleado, tal como aparece en asistencia_pendiente_hoy.'],
                                    'estatus' => ['type' => 'string', 'enum' => array_column(EstatusAsistencia::cases(), 'value')],
                                    'fecha_inicio' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, para vacaciones, licencias o comisiones.'],
                                    'fecha_fin' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, no anterior a fecha_inicio.'],
                                    'notas' => ['type' => ['string', 'null'], 'description' => 'Hasta 1000 caracteres.'],
                                ],
                                'required' => ['no_empleado', 'estatus'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['estacion', 'registros'],
                    'additionalProperties' => false,
                ],
                'escritura' => true,
            ],
        ];
    }

    public function existe(string $nombre): bool
    {
        return in_array($nombre, array_column($this->definiciones(), 'name'), true);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{contenido: string, error: bool, fuentes: list<array<string, string>>}
     */
    public function ejecutar(string $nombre, array $entrada, string $cliente): array
    {
        try {
            return match ($nombre) {
                'catalogo_dashboard' => $this->resultado($this->catalogo->texto()),
                'asistencia_pendiente_hoy' => $this->resultado($this->json($this->pendientesHoy($entrada['estacion'] ?? null))),
                'capturar_asistencia' => $this->resultado($this->json($this->captura->capturar($entrada, $cliente))),
                default => $this->consulta($nombre, $entrada),
            };
        } catch (InvalidArgumentException $e) {
            return $this->resultado($e->getMessage(), true);
        } catch (Throwable $e) {
            report($e);

            return $this->resultado('La operación no se pudo ejecutar. Revisa los parámetros.', true);
        }
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{contenido: string, error: bool, fuentes: list<array<string, string>>}
     */
    private function consulta(string $nombre, array $entrada): array
    {
        // Una instancia por llamada: HerramientasAsistente acumula las fuentes que cita.
        $herramientas = app(HerramientasAsistente::class);
        $resultado = $herramientas->ejecutar($nombre, $entrada);

        return $this->resultado((string) $resultado['contenido'], (bool) $resultado['error'], $herramientas->fuentes());
    }

    /**
     * @return array<string, mixed>
     */
    private function pendientesHoy(mixed $texto): array
    {
        $hoy = CarbonImmutable::parse(CarbonImmutable::now((string) config('ecosistema.ia.zona_horaria'))->toDateString());

        if ($texto === null || $texto === '') {
            return [
                'fecha' => $hoy->toDateString(),
                'estaciones' => $this->asistencia->resumenDelDia($hoy)->map(fn (array $fila): array => [
                    'estacion' => $fila['estacion']->nombre,
                    'personal_activo' => $fila['total_roster'],
                    'con_asistencia' => $fila['total_capturado'],
                    'completa' => $fila['capturado'],
                ])->values()->all(),
            ];
        }

        $estacion = $this->captura->buscarEstacion($texto);

        $sinAsistencia = $this->asistencia->rosterFor($estacion, $hoy)->filter(fn (Empleado $e) => $e->registrosDiarios->isEmpty());

        return [
            'fecha' => $hoy->toDateString(),
            'estacion' => $estacion->nombre,
            'sin_asistencia' => $sinAsistencia->count(),
            'personal' => $sinAsistencia->take(self::MAX_PENDIENTES)->map(fn (Empleado $e): array => [
                'no_empleado' => $e->no_empleado,
                'nombre' => $e->nombre_completo,
                'puesto' => $e->puesto,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function json(array $datos): string
    {
        return (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param  list<array<string, string>>  $fuentes
     * @return array{contenido: string, error: bool, fuentes: list<array<string, string>>}
     */
    private function resultado(string $contenido, bool $error = false, array $fuentes = []): array
    {
        return ['contenido' => $contenido, 'error' => $error, 'fuentes' => $fuentes];
    }
}
