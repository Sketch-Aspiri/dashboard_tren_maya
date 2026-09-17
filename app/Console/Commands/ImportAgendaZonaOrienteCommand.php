<?php

namespace App\Console\Commands;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "Base de Datos Zona Oriente" sheet of the Jefe de Zona's
 * roster spreadsheet into the `empleados` table (Agenda Zona Oriente ->
 * Personal). Scope is deliberately limited to that single sheet — the
 * other sheets (Estado de Fuerza, Cuadrillas de Mantenimiento,
 * BAJAS 2025-2026, metas 2025) are out of scope for this import, per the
 * confirmed decision recorded in CLAUDE.md.
 *
 * The source file contains real employee PII (CURP, RFC, NSS, domicilio,
 * etc.) — this command never prints row data or PII to the console, only
 * aggregate counts.
 */
class ImportAgendaZonaOrienteCommand extends Command
{
    private const SHEET_NAME = 'Base de Datos Zona Oriente';

    private const DEFAULT_IMPORT_DIR = 'private/imports/agenda-zona-oriente';

    private const EMPLOYEE_ID_COLUMN = 3;

    private const FECHA_INGRESO_COLUMN = 11;

    private const FECHA_NACIMIENTO_COLUMN = 19;

    /**
     * The source file mixes real Excel date cells with free-text Spanish
     * dates (e.g. "26/12/68", "Domingo, 01 de septiembre de 2024"). Slash
     * dates are day/month/year (Mexican convention), matching the
     * unambiguous long-form dates elsewhere in the same file.
     *
     * @var array<string, int>
     */
    private const SPANISH_MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /** Non-empty date cells that could not be parsed into a real date, this run. */
    private int $unparsedDateCount = 0;

    /**
     * 0-based spreadsheet column => `empleados` column mapping. Columns
     * not listed (e.g. the computed "Meses en activo", "Edad en numero",
     * "Fecha Calculo", or the future "FOTO" upload column) are
     * intentionally skipped — see CLAUDE.md YAGNI guidance.
     *
     * @var array<int, string>
     */
    private const COLUMN_MAP = [
        1 => 'estacion_codigo',
        2 => 'plaza_actual',
        // 3 (raw employee id) is handled separately -> no_empleado/estatus.
        5 => 'nombre_completo',
        6 => 'puesto',
        7 => 'nivel_plaza',
        8 => 'ultimo_grado_estudios',
        9 => 'titulo',
        10 => 'cedula',
        // 11 (fecha_ingreso) is a date column, handled separately.
        14 => 'telefono',
        15 => 'correo',
        16 => 'tipo_sangre',
        17 => 'alergias',
        // 19 (fecha_nacimiento) is a date column, handled separately.
        20 => 'lugar_nacimiento',
        21 => 'estado_civil',
        22 => 'curp',
        23 => 'rfc',
        24 => 'nss',
        25 => 'domicilio',
        26 => 'contacto_emergencia_nombre',
        27 => 'contacto_emergencia_telefono',
        28 => 'desempeno',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-agenda-zona-oriente {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/agenda-zona-oriente/*.xlsx)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa la hoja "Base de Datos Zona Oriente" del roster hacia la tabla empleados (Agenda Zona Oriente -> Personal).';

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

        $this->components->info('Leyendo archivo de origen...');

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            $this->components->error('No fue posible leer el archivo: '.$exception->getMessage());

            return self::FAILURE;
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        if (! $sheet instanceof Worksheet) {
            $this->components->error('No se encontró la hoja "'.self::SHEET_NAME.'" en el archivo.');

            return self::FAILURE;
        }

        $summary = $this->importSheet($sheet);

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array{creadas: int, actualizadas: int, omitidas: int, ocultas: int, total: int, fechas_no_reconocidas: int}
     */
    private function importSheet(Worksheet $sheet): array
    {
        $this->unparsedDateCount = 0;
        $summary = ['creadas' => 0, 'actualizadas' => 0, 'omitidas' => 0, 'ocultas' => 0, 'total' => 0];

        DB::transaction(function () use ($sheet, &$summary) {
            $highestRow = $sheet->getHighestDataRow();

            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $summary['total']++;

                // Rows hidden in the source file (e.g. stale/superseded
                // entries the Jefe de Zona filtered out) are excluded —
                // only what's actually visible when the file is opened
                // normally gets imported.
                if (! $sheet->isRowVisible($rowNumber)) {
                    $summary['ocultas']++;

                    continue;
                }

                if ($this->isRowEmpty($sheet, $rowNumber)) {
                    $summary['omitidas']++;

                    continue;
                }

                $summary[$this->importRow($sheet, $rowNumber)]++;
            }
        });

        $summary['fechas_no_reconocidas'] = $this->unparsedDateCount;

        return $summary;
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

    private function isRowEmpty(Worksheet $sheet, int $rowNumber): bool
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $address = Coordinate::stringFromColumnIndex($col).$rowNumber;
            $value = $sheet->getCell($address)->getCalculatedValue();

            if ($this->normalizeString($value) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string "creadas"|"actualizadas"
     */
    private function importRow(Worksheet $sheet, int $rowNumber): string
    {
        $attributes = $this->readMappedColumns($sheet, $rowNumber);

        // Preserves the row's position in the source sheet so the Agenda
        // Zona Oriente listing can default-sort to match the Excel order
        // the Jefe de Zona expects, instead of an arbitrary default.
        $attributes['orden_origen'] = $rowNumber;

        $attributes['fecha_ingreso'] = $this->readDateCell($sheet, $rowNumber, self::FECHA_INGRESO_COLUMN);
        $attributes['fecha_nacimiento'] = $this->readDateCell($sheet, $rowNumber, self::FECHA_NACIMIENTO_COLUMN);

        $rawEmployeeId = $this->normalizeString($this->readCell($sheet, $rowNumber, self::EMPLOYEE_ID_COLUMN));

        if ($rawEmployeeId !== null && strtoupper($rawEmployeeId) === 'VACANTE') {
            return $this->upsertVacante($attributes);
        }

        return $this->upsertActivo($attributes, $rawEmployeeId);
    }

    /**
     * @return array<string, ?string>
     */
    private function readMappedColumns(Worksheet $sheet, int $rowNumber): array
    {
        $attributes = [];

        foreach (self::COLUMN_MAP as $col0 => $field) {
            $attributes[$field] = $this->normalizeString($this->readCell($sheet, $rowNumber, $col0));
        }

        return $attributes;
    }

    /**
     * Matches vacant positions on (estación, puesto, plaza) since the sheet
     * gives vacant rows no natural unique key. Today every VACANTE row in
     * the source file has a distinct combination of those three fields, so
     * re-imports stay idempotent — but if two vacancies ever share the same
     * estación/puesto/plaza, this would silently collapse them into one row
     * instead of creating two. Revisit if the source file's shape changes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertVacante(array $attributes): string
    {
        $attributes['no_empleado'] = null;
        $attributes['estatus'] = EmpleadoEstatus::Vacante->value;

        $empleado = Empleado::query()->firstOrCreate([
            'estatus' => EmpleadoEstatus::Vacante->value,
            'estacion_codigo' => $attributes['estacion_codigo'],
            'puesto' => $attributes['puesto'],
            'plaza_actual' => $attributes['plaza_actual'],
        ], $attributes);

        return $empleado->wasRecentlyCreated ? 'creadas' : 'actualizadas';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertActivo(array $attributes, ?string $noEmpleado): string
    {
        $attributes['no_empleado'] = $noEmpleado;
        $attributes['estatus'] = EmpleadoEstatus::Activo->value;

        $empleado = Empleado::query()->updateOrCreate(
            ['no_empleado' => $noEmpleado],
            $attributes,
        );

        return $empleado->wasRecentlyCreated ? 'creadas' : 'actualizadas';
    }

    private function readCell(Worksheet $sheet, int $rowNumber, int $col0): mixed
    {
        $address = Coordinate::stringFromColumnIndex($col0 + 1).$rowNumber;

        return $sheet->getCell($address)->getCalculatedValue();
    }

    private function readDateCell(Worksheet $sheet, int $rowNumber, int $col0): ?string
    {
        $address = Coordinate::stringFromColumnIndex($col0 + 1).$rowNumber;
        $cell = $sheet->getCell($address);
        $rawValue = $cell->getCalculatedValue();

        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (is_numeric($rawValue) && ExcelDate::isDateTime($cell)) {
            return $this->carbonFromExcelSerial((float) $rawValue);
        }

        $parsed = $this->carbonFromString($this->normalizeString($rawValue));

        if ($parsed === null) {
            $this->unparsedDateCount++;
        }

        return $parsed;
    }

    private function carbonFromExcelSerial(float $serial): ?string
    {
        try {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parses a free-text date cell. Tries the source file's own
     * conventions first (day/month/year slash dates, Spanish long-form
     * dates) before falling back to Carbon's generic (English-locale,
     * month/day/year-for-slashes) parser as a last resort.
     */
    private function carbonFromString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->parseSlashDayMonthYear($value)
            ?? $this->parseSpanishLongDate($value)
            ?? $this->parseWithCarbonFallback($value);
    }

    private function parseSlashDayMonthYear(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $value, $matches) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $matches;

        return $this->toDateStringOrNull(
            $this->normalizeTwoDigitYear((int) $year),
            (int) $month,
            (int) $day,
        );
    }

    private function parseSpanishLongDate(string $value): ?string
    {
        // Strip an optional leading day-of-week ("Domingo, 01 de ...").
        $value = preg_replace('/^[a-záéíóúñ]+,\s*/iu', '', $value) ?? $value;

        if (preg_match('/^(\d{1,2})\s+(?:de\s+)?([a-záéíóúñ]+)\s+de\s+(\d{4})$/iu', $value, $matches) !== 1) {
            return null;
        }

        [, $day, $monthName, $year] = $matches;
        $month = self::SPANISH_MONTHS[$this->stripAccents(mb_strtolower($monthName))] ?? null;

        if ($month === null) {
            return null;
        }

        return $this->toDateStringOrNull((int) $year, $month, (int) $day);
    }

    private function parseWithCarbonFallback(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Matches Excel's own two-digit-year convention: 00-29 -> 2000-2029,
     * 30-99 -> 1930-1999.
     */
    private function normalizeTwoDigitYear(int $year): int
    {
        if ($year >= 100) {
            return $year;
        }

        return $year <= 29 ? 2000 + $year : 1900 + $year;
    }

    private function toDateStringOrNull(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function stripAccents(string $value): string
    {
        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /**
     * Trim, collapse internal whitespace, and normalize Excel error
     * strings/empty strings to null. Applied to every column value before
     * storing.
     */
    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        $string = preg_replace('/\s+/u', ' ', $string) ?? $string;

        if ($string === '' || preg_match('/^#[A-Z0-9\/]+[!?]$/', $string) === 1) {
            return null;
        }

        return $string;
    }

    /**
     * @param  array{creadas: int, actualizadas: int, omitidas: int, ocultas: int, total: int, fechas_no_reconocidas: int}  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->components->info('Importación completada.');

        $this->table(
            ['Creadas', 'Actualizadas', 'Omitidas', 'Ocultas en Excel', 'Total procesadas', 'Fechas no reconocidas'],
            [[
                $summary['creadas'],
                $summary['actualizadas'],
                $summary['omitidas'],
                $summary['ocultas'],
                $summary['total'],
                $summary['fechas_no_reconocidas'],
            ]],
        );

        if ($summary['fechas_no_reconocidas'] > 0) {
            $this->components->warn(
                $summary['fechas_no_reconocidas'].' celda(s) de fecha no se pudieron interpretar y quedaron vacías '
                .'(revisar manualmente el formato de fecha_ingreso/fecha_nacimiento en el archivo de origen para esas filas).',
            );
        }
    }
}
