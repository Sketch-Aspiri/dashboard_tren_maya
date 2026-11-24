<?php

namespace App\Services\Asistencia;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TurnoAsistencia;
use App\Models\Empleado;
use App\Models\RegistroDiario;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Escribe en el Google Sheet de Coordinación la asistencia que las estaciones capturaron en el Dashboard
 * (sustituye al flujo de n8n que leía los Excel de la PC). Reglas heredadas de n8n:
 *  - mañana (06:00–10:00): solo primer turno; tarde (12:00–16:00): todos;
 *  - se escribe cuando TODAS las estaciones terminaron de capturar; a la hora límite se escribe lo que haya y se avisa
 *    de lo que falta;
 *  - sábado y domingo la estación configurada no captura: todo su personal sale en DESCANSO;
 *  - reescribir las mismas celdas es inofensivo, pero una vez escrito completo no se vuelve a escribir ese turno del día.
 */
final class SincronizadorAsistenciaSheets
{
    public function __construct(
        private readonly HojaGoogleCliente $google,
        private readonly HojaAsistenciaCalculadora $calculadora,
    ) {}

    private const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /**
     * @param  string|null  $modo  manana|tarde; null = el que corresponda a la hora
     * @return array<string, mixed> resultado (siempre trae `estado`: omitido | esperando | simulado | escrito | error)
     */
    public function ejecutar(?CarbonImmutable $ahora = null, ?string $modo = null, bool $limite = false, bool $simular = false): array
    {
        $ahora = ($ahora ?? CarbonImmutable::now())->setTimezone((string) config('asistencia_sheets.zona_horaria'));

        if (! $simular && ! config('asistencia_sheets.activo')) {
            return ['estado' => 'omitido', 'motivo' => 'La escritura en Google Sheets está apagada (ASISTENCIA_SHEETS_ACTIVO).'];
        }

        $spreadsheetId = (string) config('asistencia_sheets.spreadsheet_id');

        if ($spreadsheetId === '') {
            return ['estado' => 'error', 'motivo' => 'Falta ASISTENCIA_SHEETS_ID.'];
        }

        $modo ??= $this->modoDeLaHora($ahora);

        if ($modo === null) {
            return ['estado' => 'omitido', 'motivo' => 'Fuera de las ventanas de captura.'];
        }

        $claveCompleto = 'asistencia_sheets:completo:'.$ahora->toDateString().':'.$modo;

        if (! $simular && Cache::has($claveCompleto)) {
            return ['estado' => 'omitido', 'motivo' => 'Ya se escribió completo este turno hoy.'];
        }

        [$filas, $pendientes] = $this->filasDelDia($ahora);

        if ($pendientes !== [] && ! $limite) {
            return ['estado' => 'esperando', 'motivo' => 'Faltan estaciones por terminar de capturar.', 'estaciones_pendientes' => $pendientes];
        }

        return $this->escribir($spreadsheetId, $filas, $pendientes, $modo, $ahora, $simular, $claveCompleto);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  list<string>  $pendientes
     * @return array<string, mixed>
     */
    private function escribir(string $spreadsheetId, array $filas, array $pendientes, string $modo, CarbonImmutable $ahora, bool $simular, string $claveCompleto): array
    {
        $hoja = $this->nombreDeHoja($ahora);
        $valores = $this->google->leer($spreadsheetId, "'".$hoja."'!A1:AO300");

        $calculo = $this->calculadora->calcular($filas, $valores, $modo, [
            'serial' => $this->serial($ahora),
            'dia' => $ahora->day,
            'mes' => $ahora->month,
            'iso' => $ahora->toDateString(),
        ], $hoja);

        $reporte = $calculo['reporte'] + ['estaciones_pendientes' => $pendientes];

        if ($reporte['errores'] !== [] || $simular) {
            return ['estado' => $reporte['errores'] !== [] ? 'error' : 'simulado', 'reporte' => $reporte, 'celdas' => $calculo['writes']];
        }

        $this->google->escribir($spreadsheetId, $calculo['writes']);

        if ($pendientes === [] && $reporte['incompletos'] === []) {
            Cache::put($claveCompleto, true, now()->addDays(2));
        }

        return ['estado' => 'escrito', 'reporte' => $reporte];
    }

    /**
     * Personal activo con su registro de hoy, y las estaciones que aún no terminan.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function filasDelDia(CarbonImmutable $ahora): array
    {
        $empleados = Empleado::query()
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->whereNotNull('estacion_id')
            ->with(['estacion', 'registrosDiarios' => fn ($consulta) => $consulta->whereDate('fecha', $ahora->toDateString())])
            ->orderBy('estacion_id')->orderBy('nombre_completo')
            ->get();

        $finde = $ahora->isWeekend();
        $descansa = Texto::normalizar(config('asistencia_sheets.estacion_descansa_finde'));
        $filas = [];
        $pendientes = [];

        foreach ($empleados as $empleado) {
            $nombreEstacion = (string) $empleado->estacion?->nombre;
            $estaDeDescanso = $finde && $descansa !== '' && str_contains(Texto::normalizar($nombreEstacion), $descansa);
            /** @var RegistroDiario|null $registro */
            $registro = $empleado->registrosDiarios->first();

            $filas[] = [
                'estacion' => $nombreEstacion,
                'nombre' => (string) $empleado->nombre_completo,
                'no_empleado' => (string) $empleado->no_empleado,
                'estatus' => $estaDeDescanso ? EstatusAsistencia::Descanso : $registro?->estatus,
                'turno' => $estaDeDescanso ? TurnoAsistencia::Primer : $registro?->turno,
            ];

            if (! $estaDeDescanso && $registro === null) {
                $pendientes[$nombreEstacion] = $nombreEstacion;
            }
        }

        return [$filas, array_values($pendientes)];
    }

    private function modoDeLaHora(CarbonImmutable $ahora): ?string
    {
        $minuto = $ahora->hour * 60 + $ahora->minute;

        foreach ([HojaAsistenciaCalculadora::MODO_MANANA, HojaAsistenciaCalculadora::MODO_TARDE] as $modo) {
            $ventana = (array) config('asistencia_sheets.ventanas.'.$modo);

            if ($minuto >= $this->aMinutos((string) $ventana['inicio']) && $minuto <= $this->aMinutos((string) $ventana['limite'])) {
                return $modo;
            }
        }

        return null;
    }

    private function aMinutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    private function nombreDeHoja(CarbonImmutable $ahora): string
    {
        $fija = trim((string) config('asistencia_sheets.hoja_fija'));

        return $fija !== '' ? $fija : self::MESES[$ahora->month - 1];
    }

    /** Número de serie de la fecha en Excel/Sheets (días desde 1899-12-30). */
    private function serial(CarbonImmutable $fecha): int
    {
        return (int) round(CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays(CarbonImmutable::create($fecha->year, $fecha->month, $fecha->day, 0, 0, 0, 'UTC')));
    }
}
