<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Console\Commands\Concerns\ResolvesEstacionesPorNombre;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\Vacacionista;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "ANEXO A — Vacacionistas" spreadsheet (hoja "CONSOLIDAD
 * DGTZO") into `vacacionistas` + `vacaciones_periodos` ("Agenda Zona
 * Oriente" -> Rol de vacaciones).
 *
 * One row per person: no. de empleado, nombre, días otorgados, four
 * quarterly blocks of (inicio, término, días solicitados), estación and
 * denominación de puesto. A block cell may hold two periods separated by a
 * line break ("05/10/2026\n17/11/2026"); each becomes its own row. Días
 * solicitados are recomputed as weekdays (Mon-Fri) from the dates — the
 * source formulas are NETWORKDAYS but a few point at the wrong quarter —
 * and any difference against the file's own number is reported.
 */
class ImportRolVacacionesCommand extends Command
{
    use ParsesRosterSpreadsheet, ResolvesEstacionesPorNombre;

    private const DEFAULT_IMPORT_DIR = 'private/imports/rol-vacaciones';

    /** 1-based row of the column headers; data starts on the next row. */
    private const HEADER_ROW = 4;

    // 0-based column indexes of the source sheet.
    private const COL_NO_EMPLEADO = 3;

    private const COL_NOMBRE = 4;

    private const COL_DIAS_OTORGADOS = 5;

    private const COL_ESTACION = 19;

    private const COL_PUESTO = 21;

    /**
     * First column (inicio) of each quarterly block, by trimestre. The
     * block is [inicio, término, días solicitados].
     *
     * @var array<int, int>
     */
    private const COL_TRIMESTRE = [1 => 6, 2 => 9, 3 => 12, 4 => 15];

    /**
     * Source station names the `estaciones` catalog spells differently.
     * Keys are normalizeHeader()-normalized substrings.
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'NICOLAS BRAVO' => 'Kohunlich',
    ];

    protected $signature = 'app:import-rol-vacaciones
        {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/rol-vacaciones/*.xlsx)}
        {--anio= : Año del rol, si no se puede inferir del nombre del archivo}';

    protected $description = 'Importa el rol de vacaciones (ANEXO A vacacionistas) hacia vacacionistas y vacaciones_periodos.';

    /** @var array{creadas: int, actualizadas: int, periodos: int, sin_estacion: list<string>, sin_empleado: int, fechas_ilegibles: list<string>, dias_distintos: list<string>} */
    private array $summary;

    public function handle(): int
    {
        $path = $this->resolveSourcePath();

        if ($path === null) {
            $this->components->error('No se encontró ningún archivo .xlsx para importar en storage/app/'.self::DEFAULT_IMPORT_DIR.'.');

            return self::FAILURE;
        }

        $anio = $this->resolveAnio($path);

        if ($anio === null) {
            $this->components->error('No fue posible determinar el año del rol. Usa --anio=YYYY o incluye el año en el nombre del archivo.');

            return self::FAILURE;
        }

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
        } catch (Throwable $exception) {
            $this->components->error('No fue posible leer el archivo: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->normalizeHeader($this->normalizeString($this->readCell($sheet, self::HEADER_ROW, self::COL_NO_EMPLEADO))) !== 'NO. EMPLEADO') {
            $this->components->error('El archivo no tiene el formato esperado (falta el encabezado "NO. EMPLEADO" en la fila '.self::HEADER_ROW.').');

            return self::FAILURE;
        }

        $this->summary = [
            'creadas' => 0, 'actualizadas' => 0, 'periodos' => 0, 'sin_estacion' => [],
            'sin_empleado' => 0, 'fechas_ilegibles' => [], 'dias_distintos' => [],
        ];

        DB::transaction(fn () => $this->importSheet($sheet, $anio));

        $this->renderSummary($anio);

        return self::SUCCESS;
    }

    private function resolveSourcePath(): ?string
    {
        $path = $this->argument('path');

        if ($path !== null) {
            return $path;
        }

        $directory = storage_path('app/'.self::DEFAULT_IMPORT_DIR);

        return (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [])[0] ?? null;
    }

    private function resolveAnio(string $path): ?int
    {
        $option = $this->option('anio');

        if ($option !== null && is_numeric($option)) {
            return (int) $option;
        }

        // A standalone 20xx: skips the "0317"/"160226" document numbers.
        if (preg_match('/(?<!\d)(20\d{2})(?!\d)/', basename($path), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function importSheet(Worksheet $sheet, int $anio): void
    {
        $estaciones = $this->estacionesPorNombreNormalizadoDescendente();
        $highestRow = $sheet->getHighestDataRow();

        for ($row = self::HEADER_ROW + 1; $row <= $highestRow; $row++) {
            $noEmpleado = $this->normalizeString($this->readCell($sheet, $row, self::COL_NO_EMPLEADO));
            $nombre = $this->normalizeString($this->readCell($sheet, $row, self::COL_NOMBRE));

            if ($noEmpleado === null || $nombre === null) {
                continue;
            }

            $vacacionista = $this->guardarVacacionista($sheet, $row, $anio, $noEmpleado, $nombre, $estaciones);
            $this->reemplazarPeriodos($vacacionista, $sheet, $row);
        }
    }

    /**
     * @param  array<string, Estacion>  $estaciones
     */
    private function guardarVacacionista(Worksheet $sheet, int $row, int $anio, string $noEmpleado, string $nombre, array $estaciones): Vacacionista
    {
        $estacionTexto = $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTACION));
        $estacion = $estacionTexto === null
            ? null
            : $this->resolverEstacion($this->normalizeHeader($estacionTexto), $estaciones, self::ALIAS);

        if ($estacion === null) {
            $this->summary['sin_estacion'][] = $noEmpleado.' ('.($estacionTexto ?? 'vacía').')';
        }

        $empleadoId = Empleado::query()->where('no_empleado', $noEmpleado)->value('id');

        if ($empleadoId === null) {
            $this->summary['sin_empleado']++;
        }

        $diasOtorgados = $this->normalizeString($this->readCell($sheet, $row, self::COL_DIAS_OTORGADOS));

        $vacacionista = Vacacionista::query()->firstOrNew(['anio' => $anio, 'no_empleado' => $noEmpleado]);
        $this->summary[$vacacionista->exists ? 'actualizadas' : 'creadas']++;

        $vacacionista->fill([
            'nombre_completo' => $nombre,
            'denominacion_puesto' => $this->normalizeString($this->readCell($sheet, $row, self::COL_PUESTO)),
            'dias_otorgados' => is_numeric($diasOtorgados) ? (int) $diasOtorgados : null,
            'estacion_id' => $estacion?->id,
            'empleado_id' => $empleadoId,
        ])->save();

        return $vacacionista;
    }

    /**
     * Replaces the person's periods wholesale so a re-import mirrors the
     * file exactly (a period removed from the Excel disappears here too).
     */
    private function reemplazarPeriodos(Vacacionista $vacacionista, Worksheet $sheet, int $row): void
    {
        $vacacionista->periodos()->delete();

        foreach (self::COL_TRIMESTRE as $trimestre => $columnaInicio) {
            $periodos = $this->periodosDelTrimestre($vacacionista, $sheet, $row, $trimestre, $columnaInicio);

            foreach ($periodos as $periodo) {
                $vacacionista->periodos()->create($periodo);
                $this->summary['periodos']++;
            }
        }
    }

    /**
     * @return list<array{trimestre: int, fecha_inicio: string, fecha_termino: string, dias_solicitados: int}>
     */
    private function periodosDelTrimestre(Vacacionista $vacacionista, Worksheet $sheet, int $row, int $trimestre, int $columnaInicio): array
    {
        $inicios = $this->fechasDeCelda($sheet, $row, $columnaInicio);
        $terminos = $this->fechasDeCelda($sheet, $row, $columnaInicio + 1);

        if ($inicios === [] && $terminos === []) {
            return [];
        }

        if (count($inicios) !== count($terminos)) {
            $this->summary['fechas_ilegibles'][] = $vacacionista->no_empleado.' T'.$trimestre;

            return [];
        }

        $periodos = [];

        foreach ($inicios as $indice => $inicio) {
            $termino = $terminos[$indice];
            $dias = $this->diasHabiles($inicio, $termino);

            $periodos[] = [
                'trimestre' => $trimestre,
                'fecha_inicio' => $inicio,
                'fecha_termino' => $termino,
                'dias_solicitados' => $dias,
            ];
        }

        $this->compararDiasConElExcel($vacacionista, $sheet, $row, $trimestre, $columnaInicio + 2, $periodos);

        return $periodos;
    }

    /**
     * Reads every date in a cell: a real Excel date, or free text holding
     * one or more day/month/year dates (two periods in one cell are split
     * by a line break). Unreadable non-empty text is reported, not guessed.
     *
     * @return list<string> Y-m-d
     */
    private function fechasDeCelda(Worksheet $sheet, int $row, int $col0): array
    {
        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col0 + 1).$row);
        $raw = $cell->getCalculatedValue();

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_numeric($raw) && ExcelDate::isDateTime($cell)) {
            $fecha = $this->carbonFromExcelSerial((float) $raw);

            return $fecha === null ? [] : [$fecha];
        }

        preg_match_all('#\d{1,2}/\d{1,2}/\d{2,4}#', (string) $raw, $matches);
        $fechas = array_values(array_filter(array_map($this->parseSlashDayMonthYear(...), $matches[0])));

        if ($fechas === []) {
            $this->summary['fechas_ilegibles'][] = 'fila '.$row.' col '.($col0 + 1).': '.$this->normalizeString($raw);
        }

        return $fechas;
    }

    /**
     * Mon-Fri days between two dates, both inclusive (Excel's NETWORKDAYS
     * without a holiday list).
     */
    private function diasHabiles(string $inicio, string $termino): int
    {
        $dias = 0;

        for ($dia = Carbon::parse($inicio); $dia->lte(Carbon::parse($termino)); $dia->addDay()) {
            $dias += $dia->isWeekday() ? 1 : 0;
        }

        return $dias;
    }

    /**
     * @param  list<array{trimestre: int, fecha_inicio: string, fecha_termino: string, dias_solicitados: int}>  $periodos
     */
    private function compararDiasConElExcel(Vacacionista $vacacionista, Worksheet $sheet, int $row, int $trimestre, int $columnaDias, array $periodos): void
    {
        $diasExcel = $this->readCell($sheet, $row, $columnaDias);
        $diasCalculados = array_sum(array_column($periodos, 'dias_solicitados'));

        // Multi-period cells hold text ("5 /\n5"), not a single number.
        if (is_numeric($diasExcel) && (int) $diasExcel !== $diasCalculados) {
            $this->summary['dias_distintos'][] = $vacacionista->no_empleado.' T'.$trimestre.': Excel '.(int) $diasExcel.' / calculado '.$diasCalculados;
        }
    }

    private function renderSummary(int $anio): void
    {
        $this->components->info("Importación del rol de vacaciones {$anio} completada.");

        $this->table(
            ['Creados', 'Actualizados', 'Periodos', 'Sin estación', 'Sin empleado en Personal', 'Fechas ilegibles', 'Días distintos al Excel'],
            [[
                $this->summary['creadas'],
                $this->summary['actualizadas'],
                $this->summary['periodos'],
                count($this->summary['sin_estacion']),
                $this->summary['sin_empleado'],
                count($this->summary['fechas_ilegibles']),
                count($this->summary['dias_distintos']),
            ]],
        );

        foreach ([
            'sin_estacion' => 'Estación no reconocida (revisar manualmente)',
            'fechas_ilegibles' => 'Fechas que no se pudieron leer (periodo omitido)',
            'dias_distintos' => 'Días solicitados distintos al Excel (se guardó el calculado)',
        ] as $clave => $titulo) {
            if ($this->summary[$clave] !== []) {
                $this->components->warn($titulo.': '.implode('; ', $this->summary[$clave]));
            }
        }
    }
}
