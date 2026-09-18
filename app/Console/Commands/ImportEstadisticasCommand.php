<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "FLUJO Y BOLETOS <MES>" sheets of the Jefe de Zona's
 * estadísticas spreadsheet into `estadisticas_diarias` (módulo
 * "Estadísticas" — ver el plan aprobado). The "GLOBAL ENE - DIC" and
 * "Estadistica del mes" sheets are derived sums only and are never
 * imported.
 *
 * Each monthly sheet has one block per estación: a header row with the
 * estación name, followed by a "DÍA" header row, then one row per day
 * (día, abordan, boletos vendidos), closed by a "TOTAL..." row. Blocks for
 * an unrecognized estación name are skipped entirely (never fail the whole
 * import) and reported in the summary for manual follow-up.
 */
class ImportEstadisticasCommand extends Command
{
    use ParsesRosterSpreadsheet;

    private const DEFAULT_IMPORT_DIR = 'private/imports/estadisticas';

    private const SHEET_PREFIX = 'FLUJO Y BOLETOS ';

    /**
     * Sheet-name suffix => calendar month.
     *
     * @var array<string, int>
     */
    private const MES_POR_SUFIJO = [
        'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AGO' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12,
    ];

    /**
     * Explicit alias for a source-file station header that doesn't match
     * the `estaciones` catalog name directly — "Nicolás Bravo" in the
     * Excel is the same physical station the catalog calls "Kohunlich"
     * (confirmed by the Jefe de Zona; the catalog is never renamed). Keys
     * are normalizeHeader()-normalized (uppercase, accent-stripped)
     * substrings matched against the source header text.
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'NICOLAS BRAVO' => 'Kohunlich',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-estadisticas
        {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/estadisticas/*.xlsx)}
        {--anio= : Año de los datos, si no se puede inferir del nombre del archivo}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa las hojas "FLUJO Y BOLETOS <MES>" del archivo de estadísticas hacia estadisticas_diarias.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->resolveSourcePath();

        if ($path === null) {
            $this->components->error('No se encontró ningún archivo .xlsx para importar en storage/app/'.self::DEFAULT_IMPORT_DIR.'.');

            return self::FAILURE;
        }

        $anio = $this->resolveAnio($path);

        if ($anio === null) {
            $this->components->error('No fue posible determinar el año de los datos. Usa --anio=YYYY o incluye el año en el nombre del archivo.');

            return self::FAILURE;
        }

        $this->components->info('Leyendo archivo de origen...');

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            $this->components->error('No fue posible leer el archivo: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = ['creadas' => 0, 'actualizadas' => 0, 'omitidas' => 0];
        $estacionesNoReconocidas = [];
        $estacionesPorNombre = $this->estacionesPorNombreNormalizadoDescendente();

        DB::transaction(function () use ($spreadsheet, $anio, &$summary, &$estacionesNoReconocidas, $estacionesPorNombre) {
            foreach (self::MES_POR_SUFIJO as $sufijo => $mes) {
                $sheet = $spreadsheet->getSheetByName(self::SHEET_PREFIX.$sufijo);

                if (! $sheet instanceof Worksheet) {
                    continue;
                }

                $this->importSheet($sheet, $mes, $anio, $summary, $estacionesNoReconocidas, $estacionesPorNombre);
            }
        });

        $this->renderSummary($summary, $estacionesNoReconocidas);

        return self::SUCCESS;
    }

    private function resolveSourcePath(): ?string
    {
        $path = $this->argument('path');

        if ($path !== null) {
            return $path;
        }

        $directory = storage_path('app/'.self::DEFAULT_IMPORT_DIR);

        if (! is_dir($directory)) {
            return null;
        }

        $matches = glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];

        return $matches[0] ?? null;
    }

    private function resolveAnio(string $path): ?int
    {
        $option = $this->option('anio');

        if ($option !== null && is_numeric($option)) {
            return (int) $option;
        }

        if (preg_match('/\d{4}/', basename($path), $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    /**
     * @param  array{creadas: int, actualizadas: int, omitidas: int}  $summary
     * @param  list<string>  $estacionesNoReconocidas
     * @param  array<string, Estacion>  $estacionesPorNombre
     */
    private function importSheet(
        Worksheet $sheet,
        int $mes,
        int $anio,
        array &$summary,
        array &$estacionesNoReconocidas,
        array $estacionesPorNombre,
    ): void {
        $highestRow = $sheet->getHighestDataRow();
        $estacionActual = null;

        for ($row = 1; $row <= $highestRow; $row++) {
            $celdaARaw = $this->readCell($sheet, $row, 0);
            $celdaANormalizada = $this->normalizeHeader($this->normalizeString($celdaARaw));

            if ($celdaANormalizada !== null && str_starts_with($celdaANormalizada, 'TOTAL')) {
                $estacionActual = null;

                continue;
            }

            if ($this->esFilaDeEncabezadoDeEstacion($sheet, $row, $highestRow, $celdaARaw, $celdaANormalizada)) {
                $estacionActual = $this->resolverEstacion($celdaANormalizada, $estacionesPorNombre);

                if ($estacionActual === null) {
                    $this->registrarEstacionNoReconocida($celdaARaw, $estacionesNoReconocidas);
                }

                continue;
            }

            if ($estacionActual === null || ! is_numeric($celdaARaw)) {
                continue;
            }

            $this->importarDia($estacionActual, (int) $celdaARaw, $mes, $anio, $sheet, $row, $summary);
        }
    }

    /**
     * A row is a station-header row when its column-A text isn't "DÍA" or
     * a "TOTAL..." row, isn't numeric, and the very next row's column A is
     * "DÍA" (the start of that station's daily block).
     */
    private function esFilaDeEncabezadoDeEstacion(
        Worksheet $sheet,
        int $row,
        int $highestRow,
        mixed $celdaARaw,
        ?string $celdaANormalizada,
    ): bool {
        if ($celdaANormalizada === null || $celdaANormalizada === 'DIA' || is_numeric($celdaARaw)) {
            return false;
        }

        if ($row >= $highestRow) {
            return false;
        }

        $siguienteNormalizada = $this->normalizeHeader($this->normalizeString($this->readCell($sheet, $row + 1, 0)));

        return $siguienteNormalizada === 'DIA';
    }

    /**
     * @param  list<string>  $estacionesNoReconocidas
     */
    private function registrarEstacionNoReconocida(mixed $celdaARaw, array &$estacionesNoReconocidas): void
    {
        $textoOriginal = $this->normalizeString($celdaARaw);

        if ($textoOriginal !== null && ! in_array($textoOriginal, $estacionesNoReconocidas, true)) {
            $estacionesNoReconocidas[] = $textoOriginal;
        }
    }

    /**
     * @param  array{creadas: int, actualizadas: int, omitidas: int}  $summary
     */
    private function importarDia(Estacion $estacion, int $dia, int $mes, int $anio, Worksheet $sheet, int $row, array &$summary): void
    {
        $abordan = $this->normalizeNumericCell($this->readCell($sheet, $row, 1));
        $boletosVendidos = $this->normalizeNumericCell($this->readCell($sheet, $row, 2));

        if ($abordan === null && $boletosVendidos === null) {
            $summary['omitidas']++;

            return;
        }

        if (! checkdate($mes, $dia, $anio)) {
            $summary['omitidas']++;

            return;
        }

        $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

        // Looks up the existing row via whereDate() rather than
        // updateOrCreate()'s literal attribute-equality search — same
        // reasoning as EstadisticaDiariaService::guardarDia() (SQLite,
        // used in tests, stores the 'date'-cast column with a full
        // "Y-m-d H:i:s" value that a bare "Y-m-d" search would never match).
        $registro = EstadisticaDiaria::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $fecha)
            ->first();

        $esNueva = $registro === null;
        $registro ??= new EstadisticaDiaria(['estacion_id' => $estacion->id, 'fecha' => $fecha]);

        $registro->fill([
            'abordan' => $abordan ?? 0,
            'boletos_vendidos' => $boletosVendidos ?? 0,
            'registrado_por' => null,
        ])->save();

        $summary[$esNueva ? 'creadas' : 'actualizadas']++;
    }

    private function normalizeNumericCell(mixed $value): ?int
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    /**
     * @param  array<string, Estacion>  $estacionesPorNombre
     */
    private function resolverEstacion(string $normalizedHeader, array $estacionesPorNombre): ?Estacion
    {
        foreach (self::ALIAS as $aliasClave => $nombreEstacion) {
            if (str_contains($normalizedHeader, $aliasClave)) {
                return $estacionesPorNombre[$this->normalizeHeader($nombreEstacion)] ?? null;
            }
        }

        // Iterated longest-name-first (see
        // estacionesPorNombreNormalizadoDescendente()) so e.g. "Tulum
        // Aeropuerto" is never matched by the shorter "Tulum" prefix.
        foreach ($estacionesPorNombre as $nombreNormalizado => $estacion) {
            if (str_contains($normalizedHeader, $nombreNormalizado)) {
                return $estacion;
            }
        }

        return null;
    }

    /**
     * @return array<string, Estacion>
     */
    private function estacionesPorNombreNormalizadoDescendente(): array
    {
        $indexed = [];

        foreach (Estacion::query()->get() as $estacion) {
            $nombreNormalizado = $this->normalizeHeader($estacion->nombre);

            if ($nombreNormalizado !== null) {
                $indexed[$nombreNormalizado] = $estacion;
            }
        }

        uksort($indexed, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $indexed;
    }

    /**
     * Uppercase + strip accents + collapse whitespace, for matching
     * station-header text against the `estaciones` catalog and against the
     * "DÍA"/"TOTAL..." row markers, independent of accents/casing in the
     * source file.
     */
    private function normalizeHeader(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(mb_strtoupper($value));
        $normalized = strtr($normalized, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array{creadas: int, actualizadas: int, omitidas: int}  $summary
     * @param  list<string>  $estacionesNoReconocidas
     */
    private function renderSummary(array $summary, array $estacionesNoReconocidas): void
    {
        $this->components->info('Importación completada.');

        $this->table(
            ['Creadas', 'Actualizadas', 'Omitidas', 'Estaciones no reconocidas'],
            [[
                $summary['creadas'],
                $summary['actualizadas'],
                $summary['omitidas'],
                count($estacionesNoReconocidas),
            ]],
        );

        if ($estacionesNoReconocidas !== []) {
            $this->components->warn(
                'Encabezados de estación no reconocidos (revisar manualmente): '.implode(', ', $estacionesNoReconocidas),
            );
        }
    }
}
