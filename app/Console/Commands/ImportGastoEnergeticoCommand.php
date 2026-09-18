<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Console\Commands\Concerns\ResolvesEstacionesPorNombre;
use App\Enums\TipoServicio;
use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "ANEXO B — Informe de reducción de energía eléctrica"
 * spreadsheet into `servicios_estacion` + `pagos_servicio` ("Estadísticas"
 * -> Gasto energético).
 *
 * Only the consolidated "ZONA ORIENTE" sheet is read; the per-station
 * sheets are the same figures with extra working notes and are ignored
 * (same rule as the derived sheets of app:import-estadisticas). That sheet
 * holds two stacked tables — "Pago de servicio de agua" and "Pago de
 * servicio de energía eléctrica" — each with a year row, a month row and
 * one row per estación (contrato, proveedor, one column per month,
 * observaciones).
 *
 * The year header cells are merged and do not line up with the months, so
 * the year of each month column is derived from the sequence itself: it
 * starts at the first year header and advances every time the month number
 * wraps around (Dic -> Ene).
 */
class ImportGastoEnergeticoCommand extends Command
{
    use ParsesRosterSpreadsheet, ResolvesEstacionesPorNombre;

    private const DEFAULT_IMPORT_DIR = 'private/imports/estadisticas/gasto energetico';

    private const SHEET_NAME = 'ZONA ORIENTE';

    // 0-based column indexes.
    private const COL_ESTACION = 1;

    private const COL_CONTRATO = 2;

    private const COL_PROVEEDOR = 3;

    private const COL_PRIMER_MES = 4;

    /**
     * Normalized table title => tipo de servicio.
     *
     * @var array<string, TipoServicio>
     */
    private const TITULOS = [
        'PAGO DE SERVICIO DE AGUA' => TipoServicio::Agua,
        'PAGO DE SERVICIO DE ENERGIA ELECTRICA' => TipoServicio::EnergiaElectrica,
    ];

    /**
     * Month header (first three letters, normalized) => calendar month.
     *
     * @var array<string, int>
     */
    private const MES_POR_PREFIJO = [
        'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AGO' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12,
    ];

    /**
     * Source station names the `estaciones` catalog spells differently
     * (after the "23_" prefix and trailing dot are stripped).
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'NICOLAS BRAVO' => 'Kohunlich',
        'EZE' => 'Edificio Zonal Este',
    ];

    protected $signature = 'app:import-gasto-energetico
        {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/estadisticas/gasto energetico/*.xlsx)}';

    protected $description = 'Importa el informe de gasto energético (ANEXO B) hacia servicios_estacion y pagos_servicio.';

    /** @var array{servicios: int, pagos_creados: int, pagos_actualizados: int, estaciones: list<string>, montos: list<string>} */
    private array $summary;

    public function handle(): int
    {
        $path = $this->argument('path') ?? $this->defaultPath();

        if ($path === null) {
            $this->components->error('No se encontró ningún archivo .xlsx para importar en storage/app/'.self::DEFAULT_IMPORT_DIR.'.');

            return self::FAILURE;
        }

        try {
            $sheet = IOFactory::load($path)->getSheetByName(self::SHEET_NAME);
        } catch (Throwable $exception) {
            $this->components->error('No fue posible leer el archivo: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $sheet instanceof Worksheet) {
            $this->components->error('El archivo no tiene la hoja "'.self::SHEET_NAME.'".');

            return self::FAILURE;
        }

        $this->summary = ['servicios' => 0, 'pagos_creados' => 0, 'pagos_actualizados' => 0, 'estaciones' => [], 'montos' => []];

        $tablas = $this->localizarTablas($sheet);

        if ($tablas === []) {
            $this->components->error('No se encontró ninguna tabla de pagos de agua o energía eléctrica en la hoja.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($sheet, $tablas) {
            $estaciones = $this->estacionesPorNombreNormalizadoDescendente();

            foreach ($tablas as $filaTitulo => $tipo) {
                $this->importarTabla($sheet, $filaTitulo, $tipo, $estaciones);
            }
        });

        $this->renderSummary();

        return self::SUCCESS;
    }

    private function defaultPath(): ?string
    {
        $directory = storage_path('app/'.self::DEFAULT_IMPORT_DIR);

        return (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [])[0] ?? null;
    }

    /**
     * @return array<int, TipoServicio> title row => tipo
     */
    private function localizarTablas(Worksheet $sheet): array
    {
        $tablas = [];

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $titulo = $this->normalizeHeader($this->normalizeString($this->readCell($sheet, $row, self::COL_ESTACION)));

            if ($titulo !== null && isset(self::TITULOS[$titulo])) {
                $tablas[$row] = self::TITULOS[$titulo];
            }
        }

        return $tablas;
    }

    /**
     * @param  array<string, Estacion>  $estaciones
     */
    private function importarTabla(Worksheet $sheet, int $filaTitulo, TipoServicio $tipo, array $estaciones): void
    {
        $columnas = $this->columnasDeMeses($sheet, $filaTitulo + 1, $filaTitulo + 2);
        $columnaObservaciones = $this->columnaDeObservaciones($sheet, $filaTitulo + 2);

        for ($row = $filaTitulo + 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $nombre = $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTACION));

            // The table ends at the first blank estación cell.
            if ($nombre === null) {
                return;
            }

            $estacion = $this->resolverEstacion($this->nombreDeEstacion($nombre), $estaciones, self::ALIAS);

            if ($estacion === null) {
                $this->summary['estaciones'][] = $nombre;

                continue;
            }

            $servicio = $this->guardarServicio($sheet, $row, $estacion, $tipo, $columnaObservaciones);
            $this->guardarPagos($sheet, $row, $servicio, $columnas);
        }
    }

    /**
     * Maps each month column to its [anio, mes]. The year starts at the
     * first numeric year header and advances whenever the month number
     * stops increasing.
     *
     * @return array<int, array{0: int, 1: int}> column index => [anio, mes]
     */
    private function columnasDeMeses(Worksheet $sheet, int $filaAnios, int $filaMeses): array
    {
        $anio = $this->primerAnio($sheet, $filaAnios);
        $mesAnterior = 0;
        $columnas = [];

        for ($col = self::COL_PRIMER_MES; $col <= $this->ultimaColumna($sheet); $col++) {
            $mes = $this->mesDeEncabezado($this->readCell($sheet, $filaMeses, $col));

            if ($mes === null) {
                continue;
            }

            $anio += $mes < $mesAnterior ? 1 : 0;
            $mesAnterior = $mes;
            $columnas[$col] = [$anio, $mes];
        }

        return $columnas;
    }

    private function primerAnio(Worksheet $sheet, int $filaAnios): int
    {
        for ($col = self::COL_PRIMER_MES; $col <= $this->ultimaColumna($sheet); $col++) {
            $valor = $this->normalizeString($this->readCell($sheet, $filaAnios, $col));

            if ($valor !== null && preg_match('/^\d{4}$/', $valor) === 1) {
                return (int) $valor;
            }
        }

        return (int) now()->year;
    }

    private function mesDeEncabezado(mixed $valor): ?int
    {
        $texto = $this->normalizeHeader($this->normalizeString($valor));

        return $texto === null ? null : (self::MES_POR_PREFIJO[substr($texto, 0, 3)] ?? null);
    }

    private function columnaDeObservaciones(Worksheet $sheet, int $filaMeses): ?int
    {
        for ($col = self::COL_PRIMER_MES; $col <= $this->ultimaColumna($sheet); $col++) {
            if ($this->normalizeHeader($this->normalizeString($this->readCell($sheet, $filaMeses, $col))) === 'OBSERVACIONES') {
                return $col;
            }
        }

        return null;
    }

    private function ultimaColumna(Worksheet $sheet): int
    {
        return Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) - 1;
    }

    /**
     * "23_Puerto Morelos." / "26_Tulum  Aeropuerto." => "PUERTO MORELOS" /
     * "TULUM AEROPUERTO" (normalized), ready for resolverEstacion().
     */
    private function nombreDeEstacion(string $nombre): string
    {
        $sinPrefijo = preg_replace('/^\d+_/', '', $nombre) ?? $nombre;

        return (string) $this->normalizeHeader(rtrim($sinPrefijo, '. '));
    }

    private function guardarServicio(Worksheet $sheet, int $row, Estacion $estacion, TipoServicio $tipo, ?int $columnaObservaciones): ServicioEstacion
    {
        $this->summary['servicios']++;

        return ServicioEstacion::query()->updateOrCreate(
            ['estacion_id' => $estacion->id, 'tipo' => $tipo->value],
            [
                'contrato' => $this->textoDeCelda($this->readCell($sheet, $row, self::COL_CONTRATO)),
                'proveedor' => $this->normalizeString($this->readCell($sheet, $row, self::COL_PROVEEDOR)),
                'observaciones' => $columnaObservaciones === null
                    ? null
                    : $this->textoConSaltos($this->readCell($sheet, $row, $columnaObservaciones)),
            ],
        );
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $columnas
     */
    private function guardarPagos(Worksheet $sheet, int $row, ServicioEstacion $servicio, array $columnas): void
    {
        foreach ($columnas as $col => [$anio, $mes]) {
            $monto = $this->monto($this->readCell($sheet, $row, $col), $servicio, $anio, $mes);

            if ($monto === null) {
                continue;
            }

            $pago = PagoServicio::query()->firstOrNew([
                'servicio_estacion_id' => $servicio->id,
                'anio' => $anio,
                'mes' => $mes,
            ]);
            $this->summary[$pago->exists ? 'pagos_actualizados' : 'pagos_creados']++;
            $pago->fill(['monto' => $monto])->save();
        }
    }

    /**
     * Amounts come as numbers (accounting format) or "$ 1,234.00" text; "$ -"
     * is a real $0 payment, blank is "no data" (null). Anything else is
     * reported, never guessed.
     */
    private function monto(mixed $raw, ServicioEstacion $servicio, int $anio, int $mes): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }

        $texto = (string) $raw;
        $limpio = preg_replace('/[^\d.\-]/', '', $texto) ?? '';

        if (preg_match('/[a-z]/i', $texto) === 1 || ($limpio !== '-' && ! is_numeric($limpio))) {
            $this->summary['montos'][] = $servicio->estacion->nombre.' ('.$servicio->tipo->label().')'.' '.$mes.'-'.$anio.': '.$texto;

            return null;
        }

        return $limpio === '-' ? 0.0 : round((float) $limpio, 2);
    }

    /**
     * Contract numbers arrive as numbers (12+ digits) that would print in
     * scientific notation if cast naively.
     */
    private function textoDeCelda(mixed $valor): ?string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            return sprintf('%.0f', $valor);
        }

        return $this->normalizeString($valor);
    }

    /**
     * Free-text notes keep their paragraph breaks (unlike normalizeString,
     * which flattens all whitespace).
     */
    private function textoConSaltos(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = str_replace("\r\n", "\n", trim((string) $valor));
        $texto = preg_replace(['/[ \t]+/', '/ ?\n ?/', '/\n{3,}/'], [' ', "\n", "\n\n"], $texto) ?? $texto;

        return $texto === '' ? null : $texto;
    }

    private function renderSummary(): void
    {
        $this->components->info('Importación del gasto energético completada.');

        $this->table(
            ['Servicios', 'Pagos creados', 'Pagos actualizados', 'Estaciones no reconocidas', 'Montos ilegibles'],
            [[
                $this->summary['servicios'],
                $this->summary['pagos_creados'],
                $this->summary['pagos_actualizados'],
                count($this->summary['estaciones']),
                count($this->summary['montos']),
            ]],
        );

        foreach (['estaciones' => 'Estaciones no reconocidas (omitidas)', 'montos' => 'Montos que no se pudieron leer (omitidos)'] as $clave => $titulo) {
            if ($this->summary[$clave] !== []) {
                $this->components->warn($titulo.': '.implode('; ', $this->summary[$clave]));
            }
        }
    }
}
