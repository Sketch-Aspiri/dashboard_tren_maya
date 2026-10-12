<?php

namespace App\Services\Asistente;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Support\DatosSensibles;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Herramientas (tools) que Claude puede pedir al responder una pregunta.
 * Todas son de SOLO LECTURA y con límites de filas. Para no enviar datos
 * personales a la API: Personal solo devuelve nombre/no. de empleado/
 * puesto/estación/estatus/plaza; las secciones excluyen columnas con
 * nombre sensible (ConsultaFilasSeccion); y todo valor o fragmento de
 * documento pasa por DatosSensibles::redactar() (CURP, RFC, NSS,
 * teléfonos, correos, cuentas). Es una mitigación por patrones, no una
 * garantía absoluta: el asistente es opcional y se apaga sin
 * ANTHROPIC_API_KEY. Cada ejecución registra su fuente.
 */
final class HerramientasAsistente
{
    private const MAX_PERSONAL = 50;

    private const MAX_DOCUMENTOS = 5;

    private const FRAGMENTO = 160;

    /** @var array<string, array{titulo: string, url: string}> */
    private array $fuentes = [];

    public function __construct(private readonly ConsultaFilasSeccion $consultaSeccion) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definiciones(): array
    {
        $nulable = fn (array $tipo) => [...$tipo, 'type' => [$tipo['type'], 'null']];

        return [
            [
                'name' => 'consultar_seccion',
                'description' => 'Consulta la tabla de una sección del menú (ver catálogo en las instrucciones). Filtra filas y aplica una operación: contar, listar (máx. 50), sumar/promedio/minimo/maximo de una columna numérica, o contar_por_valor (cuántas filas por cada valor de una columna). Usa los nombres de columna del catálogo.',
                'strict' => true,
                'inputSchema' => $this->esquema([
                    'seccion' => ['type' => 'string', 'description' => 'slug de la sección, del catálogo'],
                    'filtros' => ['type' => 'array', 'items' => $this->esquema([
                        'columna' => ['type' => 'string'],
                        'operador' => ['type' => 'string', 'enum' => ConsultaFilasSeccion::OPERADORES],
                        'valor' => $nulable(['type' => 'string']),
                    ])],
                    'operacion' => ['type' => 'string', 'enum' => ConsultaFilasSeccion::OPERACIONES],
                    'columna_objetivo' => $nulable(['type' => 'string', 'description' => 'columna para sumar/promedio/minimo/maximo/contar_por_valor']),
                    'limite' => $nulable(['type' => 'integer', 'description' => 'filas a listar (máx. 50)']),
                ]),
            ],
            [
                'name' => 'consultar_personal',
                'description' => 'Consulta el padrón de Personal de la Zona Oriente (RR.HH. > Personal): contar o listar empleados filtrando por estación, estatus (activo/vacante), tipo de plaza, puesto o nombre; o contar agrupando por estación o por puesto. Devuelve solo nombre, número de empleado, puesto, estación, estatus y tipo de plaza.',
                'strict' => true,
                'inputSchema' => $this->esquema([
                    'estacion' => $nulable(['type' => 'string', 'description' => 'nombre de la estación, p. ej. "Chetumal"']),
                    'estatus' => $nulable(['type' => 'string', 'enum' => [...array_column(EmpleadoEstatus::cases(), 'value'), null]]),
                    'tipo_plaza' => $nulable(['type' => 'string', 'enum' => [...array_column(TipoPlaza::cases(), 'value'), null]]),
                    'puesto_contiene' => $nulable(['type' => 'string']),
                    'nombre_contiene' => $nulable(['type' => 'string']),
                    'operacion' => ['type' => 'string', 'enum' => ['contar', 'listar', 'contar_por_estacion', 'contar_por_puesto']],
                ]),
            ],
            [
                'name' => 'consultar_asistencia',
                'description' => 'Control de Asistencia Diaria: para una fecha (por defecto hoy) devuelve por estación cuántas personas hay en cada estatus (presente, descanso, vacaciones, comisión, etc.) y si la estación ya capturó.',
                'strict' => true,
                'inputSchema' => $this->esquema([
                    'fecha' => $nulable(['type' => 'string', 'description' => 'AAAA-MM-DD']),
                    'estacion' => $nulable(['type' => 'string']),
                ]),
            ],
            [
                'name' => 'buscar_documentos',
                'description' => 'Busca en el texto de los documentos subidos (PDF, Word, Excel, PowerPoint) y devuelve hasta 5 documentos con fragmentos relevantes.',
                'strict' => true,
                'inputSchema' => $this->esquema([
                    'consulta' => ['type' => 'string', 'description' => 'palabras clave'],
                    'seccion' => $nulable(['type' => 'string', 'description' => 'slug para limitar la búsqueda a una sección']),
                ]),
            ],
        ];
    }

    /**
     * Ejecuta una herramienta y devuelve su resultado (se envía a Claude
     * como JSON). Los errores de entrada vuelven como is_error para que el
     * modelo corrija la consulta.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{contenido: string, error: bool}
     */
    public function ejecutar(string $nombre, array $entrada): array
    {
        try {
            $resultado = match ($nombre) {
                'consultar_seccion' => $this->consultarSeccion($entrada),
                'consultar_personal' => $this->consultarPersonal($entrada),
                'consultar_asistencia' => $this->consultarAsistencia($entrada),
                'buscar_documentos' => $this->buscarDocumentos($entrada),
                default => throw new \InvalidArgumentException("Herramienta desconocida: {$nombre}."),
            };

            return ['contenido' => json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'error' => false];
        } catch (\InvalidArgumentException $e) {
            return ['contenido' => $e->getMessage(), 'error' => true];
        } catch (\Throwable $e) {
            // Entrada inesperada del modelo u otro fallo: se informa a Claude
            // como error de la herramienta en lugar de cortar la respuesta.
            Log::warning('Fallo de una herramienta del asistente.', ['herramienta' => $nombre, 'error' => $e->getMessage()]);

            return ['contenido' => 'La consulta no se pudo ejecutar. Revisa los parámetros.', 'error' => true];
        }
    }

    /**
     * @return list<array{titulo: string, url: string}>
     */
    public function fuentes(): array
    {
        return array_values($this->fuentes);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function consultarSeccion(array $entrada): array
    {
        $seccion = Seccion::query()->where('slug', (string) ($entrada['seccion'] ?? ''))->first()
            ?? throw new \InvalidArgumentException('No existe una sección con ese slug. Revisa el catálogo.');

        $this->fuentes['seccion:'.$seccion->id] = ['titulo' => $seccion->nombre, 'url' => route('secciones.show', $seccion)];

        $filtros = array_map(fn ($filtro) => [
            'columna' => (string) ($filtro['columna'] ?? ''),
            'operador' => in_array($filtro['operador'] ?? null, ConsultaFilasSeccion::OPERADORES, true) ? $filtro['operador'] : 'igual',
            'valor' => isset($filtro['valor']) ? (string) $filtro['valor'] : null,
        ], array_slice((array) ($entrada['filtros'] ?? []), 0, 10));

        return $this->consultaSeccion->ejecutar(
            $seccion,
            $filtros,
            in_array($entrada['operacion'] ?? null, ConsultaFilasSeccion::OPERACIONES, true) ? $entrada['operacion'] : 'contar',
            isset($entrada['columna_objetivo']) ? (string) $entrada['columna_objetivo'] : null,
            (int) ($entrada['limite'] ?? 20),
        );
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function consultarPersonal(array $entrada): array
    {
        $this->fuentes['personal'] = ['titulo' => 'Personal', 'url' => route('agenda.personal.index')];

        $consulta = Empleado::query()->with('estacion:id,nombre');
        $estacion = $this->estacionPorNombre($entrada['estacion'] ?? null);

        if (($entrada['estacion'] ?? null) !== null && $estacion === null) {
            throw new \InvalidArgumentException('No se encontró esa estación. Estaciones: '.Estacion::query()->orderBy('orden')->pluck('nombre')->implode(', ').'.');
        }

        $consulta->when($estacion, fn (Builder $q) => $q->where('estacion_id', $estacion->id))
            ->when(in_array($entrada['estatus'] ?? null, array_column(EmpleadoEstatus::cases(), 'value'), true), fn (Builder $q) => $q->where('estatus', $entrada['estatus']))
            ->when(in_array($entrada['tipo_plaza'] ?? null, array_column(TipoPlaza::cases(), 'value'), true), fn (Builder $q) => $q->where('tipo_plaza', $entrada['tipo_plaza']))
            ->when(filled($entrada['puesto_contiene'] ?? null), fn (Builder $q) => $q->where('puesto', 'like', '%'.$this->escapar($entrada['puesto_contiene']).'%'))
            ->when(filled($entrada['nombre_contiene'] ?? null), fn (Builder $q) => $q->where('nombre_completo', 'like', '%'.$this->escapar($entrada['nombre_contiene']).'%'));

        return match ($entrada['operacion'] ?? 'contar') {
            'listar' => [
                'total' => (clone $consulta)->count(),
                'empleados' => $consulta->orderBy('nombre_completo')->limit(self::MAX_PERSONAL)->get()->map(fn (Empleado $empleado) => [
                    'nombre' => $empleado->nombre_completo,
                    'no_empleado' => $empleado->no_empleado,
                    'puesto' => $empleado->puesto,
                    'estacion' => $empleado->estacion?->nombre ?? $empleado->estacion_codigo,
                    'estatus' => $empleado->estatus?->value,
                    'tipo_plaza' => $empleado->tipo_plaza?->value,
                ])->all(),
            ],
            'contar_por_estacion' => $consulta->get()->countBy(fn (Empleado $empleado) => $empleado->estacion?->nombre ?? 'Sin estación')->sortDesc()->all(),
            'contar_por_puesto' => $consulta->get()->countBy(fn (Empleado $empleado) => $empleado->puesto ?: 'Sin puesto')->sortDesc()->take(40)->all(),
            default => ['total' => $consulta->count()],
        };
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function consultarAsistencia(array $entrada): array
    {
        $this->fuentes['asistencia'] = ['titulo' => 'Asistencia Zona Oriente', 'url' => route('asistencia.zona.index')];

        try {
            $fecha = filled($entrada['fecha'] ?? null) ? CarbonImmutable::parse($entrada['fecha']) : CarbonImmutable::today();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Fecha inválida; usa el formato AAAA-MM-DD.');
        }

        $estacion = $this->estacionPorNombre($entrada['estacion'] ?? null);

        $registros = RegistroDiario::query()
            ->whereDate('fecha', $fecha)
            ->when($estacion, fn (Builder $q) => $q->where('estacion_id', $estacion->id))
            ->with('estacion:id,nombre')
            ->get(['id', 'estacion_id', 'estatus']);

        return [
            'fecha' => $fecha->toDateString(),
            'por_estacion' => $registros->groupBy(fn (RegistroDiario $registro) => $registro->estacion?->nombre ?? 'Sin estación')
                ->map(fn ($grupo) => $grupo->countBy(fn (RegistroDiario $registro) => $registro->estatus instanceof EstatusAsistencia ? $registro->estatus->label() : (string) $registro->estatus)->all())
                ->all(),
            'estaciones_sin_captura' => Estacion::query()->where('is_operativa', true)
                ->whereNotIn('id', $registros->pluck('estacion_id')->unique())
                ->when($estacion, fn (Builder $q) => $q->whereKey($estacion->id))
                ->pluck('nombre')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function buscarDocumentos(array $entrada): array
    {
        $consulta = trim((string) ($entrada['consulta'] ?? ''));

        if ($consulta === '') {
            throw new \InvalidArgumentException('Indica palabras clave para buscar.');
        }

        $seccion = filled($entrada['seccion'] ?? null) ? Seccion::query()->where('slug', $entrada['seccion'])->first() : null;

        $documentos = SeccionArchivo::query()
            ->with('seccion:id,nombre,slug')
            ->whereHas('seccion')
            ->when($seccion, fn (Builder $q) => $q->where('seccion_id', $seccion->id))
            ->where(fn (Builder $q) => $q->whereFullText(['nombre_original', 'texto_extraido'], $consulta)
                ->orWhere('nombre_original', 'like', '%'.$this->escapar($consulta).'%'))
            ->limit(self::MAX_DOCUMENTOS)
            ->get();

        return [
            'documentos' => $documentos->map(function (SeccionArchivo $documento) use ($consulta): array {
                $url = route('secciones.archivos.show', [$documento->seccion, $documento, 'ver' => $documento->admiteVistaPrevia() ? 1 : null]);
                $this->fuentes['archivo:'.$documento->id] = ['titulo' => $documento->nombre_original, 'url' => $url];

                return [
                    'nombre' => $documento->nombre_original,
                    'seccion' => $documento->seccion->nombre,
                    'fecha' => $documento->created_at?->toDateString(),
                    'fragmentos' => $this->fragmentos((string) $documento->texto_extraido, $consulta),
                ];
            })->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function fragmentos(string $texto, string $consulta): array
    {
        $fragmentos = [];
        $palabras = array_filter(preg_split('/\s+/u', $consulta) ?: [], fn (string $palabra) => mb_strlen($palabra) >= 3);

        foreach ($palabras as $palabra) {
            $posicion = mb_stripos($texto, $palabra);

            if ($posicion !== false) {
                $inicio = max(0, $posicion - self::FRAGMENTO);
                $fragmentos[] = '…'.trim(mb_substr($texto, $inicio, self::FRAGMENTO * 2)).'…';
            }

            if (count($fragmentos) >= 3) {
                break;
            }
        }

        $fragmentos = $fragmentos !== [] ? $fragmentos : [mb_substr($texto, 0, self::FRAGMENTO * 2)];

        return array_map(fn (string $fragmento) => DatosSensibles::redactar($fragmento), $fragmentos);
    }

    private function estacionPorNombre(mixed $nombre): ?Estacion
    {
        if (! filled($nombre)) {
            return null;
        }

        $buscado = Texto::normalizar($nombre);
        $estaciones = Estacion::query()->get(['id', 'nombre']);

        return $estaciones->first(fn (Estacion $estacion) => Texto::normalizar($estacion->nombre) === $buscado)
            ?? $estaciones->first(fn (Estacion $estacion) => str_contains(Texto::normalizar($estacion->nombre), $buscado));
    }

    /**
     * @param  array<string, array<string, mixed>>  $propiedades
     * @return array<string, mixed>
     */
    private function esquema(array $propiedades): array
    {
        return [
            'type' => 'object',
            'properties' => $propiedades,
            'required' => array_keys($propiedades),
            'additionalProperties' => false,
        ];
    }

    private function escapar(mixed $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $texto);
    }
}
