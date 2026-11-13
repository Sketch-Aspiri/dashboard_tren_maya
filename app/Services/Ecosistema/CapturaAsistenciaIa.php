<?php

namespace App\Services\Ecosistema;

use App\Enums\EstatusAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Captura de asistencia pedida por la IA (puente con Tickets). Reglas, más estrictas que las de una cuenta de Zona:
 * - SOLO el día de hoy en hora de Cancún y solo estaciones operativas;
 * - solo empleados ACTIVOS del roster de esa estación, identificados por su número de empleado;
 * - NUNCA modifica un registro que ya existe (lo capturó una persona o una llamada anterior): la IA no puede
 *   sobrescribir, y la comprobación se hace dentro de la transacción (AsistenciaCapturaService::crearFaltantes);
 * - las filas inválidas se rechazan una por una y se informan; las válidas se guardan.
 * Quien registró es la cuenta de sistema (CuentaIa) y la llamada queda en la bitácora `asistencia_ia` con el cliente
 * MCP que la hizo. No genera oficios ni toca comisionados.
 */
final class CapturaAsistenciaIa
{
    public function __construct(
        private readonly AsistenciaCapturaService $capturas,
        private readonly CuentaIa $cuenta,
    ) {}

    /**
     * Estación operativa por nombre o código (sin importar mayúsculas ni acentos). Exige una única coincidencia.
     *
     * @throws InvalidArgumentException
     */
    public function buscarEstacion(mixed $texto): Estacion
    {
        $buscado = Texto::normalizar(is_string($texto) ? $texto : '');

        if ($buscado === '') {
            throw new InvalidArgumentException('Indica la estación (nombre o código).');
        }

        $coincidencias = Estacion::query()->where('is_operativa', true)->get()
            ->filter(fn (Estacion $e) => Texto::normalizar($e->nombre) === $buscado || Texto::normalizar($e->codigo) === $buscado);

        if ($coincidencias->count() !== 1) {
            throw new InvalidArgumentException('No encontré una estación operativa con ese nombre o código.');
        }

        return $coincidencias->first();
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException cuando la petición completa no es válida (estación, formato general)
     */
    public function capturar(array $entrada, string $cliente): array
    {
        if (($entrada['sobrescribir'] ?? false) !== false) {
            throw new InvalidArgumentException('La IA no puede sobrescribir asistencia ya capturada. Quita `sobrescribir`.');
        }

        $estacion = $this->buscarEstacion($entrada['estacion'] ?? null);
        $filas = $entrada['registros'] ?? null;
        $maximo = (int) config('ecosistema.ia.max_registros');

        if (! is_array($filas) || $filas === [] || ! array_is_list($filas)) {
            throw new InvalidArgumentException('`registros` debe ser una lista con al menos una fila.');
        }

        if (count($filas) > $maximo) {
            throw new InvalidArgumentException("Máximo {$maximo} registros por llamada.");
        }

        $hoy = CarbonImmutable::parse(CarbonImmutable::now((string) config('ecosistema.ia.zona_horaria'))->toDateString());
        [$validas, $rechazadas] = $this->clasificar($filas, $this->capturas->rosterFor($estacion, $hoy)->all());

        $resultado = $validas === [] ? ['creados' => [], 'existentes' => []] : $this->guardar($estacion, $hoy, $validas);

        $omitidas = $this->omitidas($resultado['existentes'], $hoy);

        activity('asistencia_ia')
            ->performedOn($estacion)
            ->causedBy($this->cuenta->usuario())
            ->event('capturada')
            ->withProperties([
                'cliente' => $cliente,
                'fecha' => $hoy->toDateString(),
                'capturados' => count($resultado['creados']),
                'omitidos' => count($omitidas),
                'rechazados' => count($rechazadas),
                'no_empleados' => array_values(array_map(fn (array $fila) => $fila['no_empleado'], array_filter($validas, fn (array $fila) => in_array($fila['empleado_id'], $resultado['creados'], true)))),
            ])
            ->log('Captura de asistencia por IA');

        return [
            'estacion' => $estacion->nombre,
            'fecha' => $hoy->toDateString(),
            'capturados' => count($resultado['creados']),
            'omitidos_ya_capturados' => $omitidas,
            'rechazados' => $rechazadas,
            'pendientes_restantes' => $this->capturas->rosterFor($estacion, $hoy)->filter(fn (Empleado $e) => $e->registrosDiarios->isEmpty())->count(),
        ];
    }

    /**
     * @param  list<mixed>  $filas
     * @param  list<Empleado>  $roster
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string|int>>}
     */
    private function clasificar(array $filas, array $roster): array
    {
        $porNumero = [];

        foreach ($roster as $empleado) {
            $clave = Texto::normalizar($empleado->no_empleado);

            if ($clave !== '') {
                $porNumero[$clave][] = $empleado;
            }
        }

        $validas = $rechazadas = $vistos = [];

        foreach ($filas as $indice => $fila) {
            $numero = is_array($fila) ? $this->texto($fila['no_empleado'] ?? null) : null;
            $clave = Texto::normalizar($numero);
            $motivo = $this->motivoDeRechazo($fila, $numero, $clave, $porNumero, $vistos);

            if ($motivo !== null) {
                // Número de fila desde 1, como lo cuenta quien lee la lista que envió.
                $rechazadas[] = ['fila' => $indice + 1, 'no_empleado' => mb_substr((string) $numero, 0, 40), 'motivo' => $motivo];

                continue;
            }

            $vistos[$clave] = true;

            $validas[] = [
                'empleado_id' => $porNumero[$clave][0]->id,
                'no_empleado' => $porNumero[$clave][0]->no_empleado,
                'estatus' => $fila['estatus'],
                'fecha_inicio' => $this->fecha($fila['fecha_inicio'] ?? null),
                'fecha_fin' => $this->fecha($fila['fecha_fin'] ?? null),
                'notas' => $this->notas($fila['notas'] ?? null),
            ];
        }

        return [$validas, $rechazadas];
    }

    /**
     * @param  array<string, list<Empleado>>  $porNumero
     * @param  array<string, true>  $vistos
     */
    private function motivoDeRechazo(mixed $fila, ?string $numero, string $clave, array $porNumero, array $vistos): ?string
    {
        return match (true) {
            ! is_array($fila) => 'La fila debe ser un objeto.',
            $numero === null => 'no_empleado debe ser texto o número.',
            $clave === '' => 'Falta no_empleado.',
            ! isset($porNumero[$clave]) => 'El no_empleado no está en el roster activo de la estación.',
            count($porNumero[$clave]) > 1 => 'El no_empleado está repetido en el roster: captúralo desde el Dashboard.',
            isset($vistos[$clave]) => 'El no_empleado aparece dos veces en la llamada.',
            ! is_string($fila['estatus'] ?? null) || EstatusAsistencia::tryFrom($fila['estatus']) === null => 'Estatus no válido.',
            default => $this->motivoDeFechas($fila['fecha_inicio'] ?? null, $fila['fecha_fin'] ?? null)
                ?? (isset($fila['notas']) && ! is_string($fila['notas']) ? 'Las notas deben ser texto.' : null),
        };
    }

    private function motivoDeFechas(mixed $inicio, mixed $fin): ?string
    {
        $fechas = [];

        foreach (['inicio' => $inicio, 'fin' => $fin] as $nombre => $valor) {
            if ($valor === null || $valor === '') {
                $fechas[$nombre] = null;

                continue;
            }

            $fecha = is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : false;

            if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                return "fecha_{$nombre} debe ser AAAA-MM-DD.";
            }

            $fechas[$nombre] = $fecha;
        }

        return $fechas['inicio'] !== null && $fechas['fin'] !== null && $fechas['fin']->lt($fechas['inicio'])
            ? 'fecha_fin no puede ser anterior a fecha_inicio.'
            : null;
    }

    /**
     * Texto de un valor escalar simple (cadena o entero); cualquier otra cosa no es un número de empleado.
     */
    private function texto(mixed $valor): ?string
    {
        return is_string($valor) || is_int($valor) ? (string) $valor : null;
    }

    /**
     * Una fecha ya validada, o null cuando viene vacía.
     */
    private function fecha(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function notas(mixed $notas): ?string
    {
        if (! is_string($notas)) {
            return null;
        }

        $limpio = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\s]+/u', ' ', $notas));

        return $limpio === '' ? null : mb_substr($limpio, 0, 1000);
    }

    /**
     * @param  list<array<string, mixed>>  $validas
     * @return array{creados: list<int>, existentes: list<int>}
     */
    private function guardar(Estacion $estacion, CarbonImmutable $hoy, array $validas): array
    {
        $actor = $this->cuenta->usuario();
        $anterior = Auth::user();

        // La bitácora automática del modelo toma a quien está autenticado: aquí es la cuenta de sistema.
        Auth::setUser($actor);

        try {
            return $this->capturas->crearFaltantes($estacion, $hoy, $validas, $actor);
        } finally {
            $anterior !== null ? Auth::setUser($anterior) : Auth::forgetUser();
        }
    }

    /**
     * Quienes ya tenían asistencia hoy: se informa el estatus que ya estaba, sin tocarlo.
     *
     * @param  list<int>  $empleadoIds
     * @return list<array<string, string>>
     */
    private function omitidas(array $empleadoIds, CarbonImmutable $hoy): array
    {
        if ($empleadoIds === []) {
            return [];
        }

        return RegistroDiario::query()
            ->with('empleado:id,no_empleado')
            ->whereIn('empleado_id', $empleadoIds)
            ->whereDate('fecha', $hoy)
            ->get()
            ->map(fn (RegistroDiario $registro): array => ['no_empleado' => (string) $registro->empleado?->no_empleado, 'estatus_actual' => $registro->estatus->label()])
            ->values()
            ->all();
    }
}
