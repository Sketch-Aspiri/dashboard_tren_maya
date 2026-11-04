<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "Base de Datos Zona Oriente" sheet of the Jefe de Zona's
 * roster spreadsheet into the `empleados` table (Agenda Zona Oriente ->
 * Personal). Scope is deliberately limited to that single sheet — the
 * "Cuadrillas de Mantenimiento" sheet has its own sibling command
 * (app:import-cuadrillas-mantenimiento); Estado de Fuerza, BAJAS
 * 2025-2026, and metas 2025 remain out of scope, per the confirmed
 * decision recorded in CLAUDE.md.
 *
 * The source file contains real employee PII (CURP, RFC, NSS, domicilio,
 * etc.) — this command never prints row data or PII to the console, only
 * aggregate counts.
 */
class ImportAgendaZonaOrienteCommand extends Command
{
    use ParsesRosterSpreadsheet;

    private const SHEET_NAME = 'Base de Datos Zona Oriente';

    private const DEFAULT_IMPORT_DIR = 'private/imports/agenda-zona-oriente';

    private const EMPLOYEE_ID_COLUMN = 3;

    private const FECHA_INGRESO_COLUMN = 11;

    private const FECHA_NACIMIENTO_COLUMN = 19;

    /** @var array<string, int> campo => columna (0-based), resuelto por hoja */
    private array $columns = [];

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
        $this->columns = $this->resolveColumns($sheet, array_flip(self::COLUMN_MAP) + [
            'no_empleado' => self::EMPLOYEE_ID_COLUMN,
            'fecha_ingreso' => self::FECHA_INGRESO_COLUMN,
            'fecha_nacimiento' => self::FECHA_NACIMIENTO_COLUMN,
        ]);
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

        $attributes['fecha_ingreso'] = $this->readOptionalDate($sheet, $rowNumber, 'fecha_ingreso');
        $attributes['fecha_nacimiento'] = $this->readOptionalDate($sheet, $rowNumber, 'fecha_nacimiento');

        $rawEmployeeId = $this->normalizeString($this->readCell($sheet, $rowNumber, $this->columns['no_empleado']));

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

        foreach (self::COLUMN_MAP as $field) {
            $col0 = $this->columns[$field] ?? null;
            $attributes[$field] = $col0 === null
                ? null
                : $this->normalizeString($this->readCell($sheet, $rowNumber, $col0));
        }

        foreach (['curp' => 18, 'rfc' => 13, 'nss' => 11] as $field => $max) {
            $attributes[$field] = $this->normalizeIdentifier($attributes[$field] ?? null, $field, $max);
        }

        return $attributes;
    }

    private function readOptionalDate(Worksheet $sheet, int $rowNumber, string $field): ?string
    {
        $col0 = $this->columns[$field] ?? null;

        return $col0 === null ? null : $this->readDateCell($sheet, $rowNumber, $col0);
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
