<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Enums\EmpleadoEstatus;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the "Cuadrillas de Mantenimiento " sheet (note the trailing
 * space in the real workbook's sheet name — verified against the source
 * file, not guessed) of the Jefe de Zona's roster spreadsheet into the
 * `empleados` table, marking every row `tipo_plaza = eventual`. Sibling
 * command to app:import-agenda-zona-oriente (which imports the
 * "Base de Datos Zona Oriente" sheet, tipo_plaza permanente/militar) —
 * both write to the same `empleados` table, matched by the same
 * `no_empleado` uniqueness, per the Fase 1 plan's confirmed decision to
 * extend Empleado instead of creating a separate `trabajadores` table.
 *
 * The source file contains real employee PII — this command never prints
 * row data or PII to the console, only aggregate counts.
 */
class ImportCuadrillasMantenimientoCommand extends Command
{
    use ParsesRosterSpreadsheet;

    private const SHEET_NAME = 'Cuadrillas de Mantenimiento ';

    private const DEFAULT_IMPORT_DIR = 'private/imports/agenda-zona-oriente';

    private const EMPLOYEE_ID_COLUMN = 3;

    private const FECHA_INGRESO_COLUMN = 10;

    private const FECHA_NACIMIENTO_COLUMN = 16;

    /**
     * 0-based spreadsheet column => `empleados` column mapping, verified
     * against the real sheet's header row (row 1, with a second header row
     * for the merged "Contacto de Emergencia" sub-columns).
     *
     * Two deliberate deviations from a generic "same layout" assumption,
     * both confirmed by directly inspecting the real file rather than
     * reusing the other sheet's mapping:
     *
     * - Column 9 ("Oficio ") maps to `titulo` — no better-fitting field
     *   exists in this sheet (documented judgment call, per the Fase 1
     *   plan's decision #7).
     * - `cedula` and `desempeno` are NOT present in this sheet at all —
     *   left null for every imported row, nothing is mapped to them.
     *
     * Unlike what a prior research pass assumed, "Contacto de Emergencia"
     * in the real file is NOT a single combined column: it's a merged
     * header (X1:Y1) over two real sub-columns — X ("Nombre") and Y
     * ("Contacto (Telefono)") — so both are mapped normally, the same
     * shape as the sibling sheet's contact columns.
     *
     * @var array<int, string>
     */
    private const COLUMN_MAP = [
        1 => 'estacion_codigo',
        2 => 'plaza_actual',
        // 3 (raw employee id) is handled separately -> no_empleado/estatus.
        5 => 'puesto', // "Cargo"
        6 => 'nivel_plaza', // "Nivel de Plaza Eventual."
        7 => 'nombre_completo',
        8 => 'ultimo_grado_estudios',
        9 => 'titulo', // "Oficio " — see class docblock.
        // 10 (fecha_ingreso) is a date column, handled separately.
        11 => 'telefono',
        12 => 'correo',
        13 => 'tipo_sangre',
        14 => 'alergias',
        // 15 ("Edad en número") is computed in the sheet, not imported.
        // 16 (fecha_nacimiento) is a date column, handled separately.
        17 => 'lugar_nacimiento',
        18 => 'estado_civil',
        19 => 'curp',
        20 => 'rfc',
        21 => 'nss',
        22 => 'domicilio',
        23 => 'contacto_emergencia_nombre',
        24 => 'contacto_emergencia_telefono',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-cuadrillas-mantenimiento {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/agenda-zona-oriente/*.xlsx)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa la hoja "Cuadrillas de Mantenimiento" del roster hacia la tabla empleados como personal eventual.';

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
            // Data starts at row 3: row 1 is the main header, row 2 holds
            // the "Contacto de Emergencia" sub-headers (Nombre/Telefono).
            $highestRow = $sheet->getHighestDataRow();

            for ($rowNumber = 3; $rowNumber <= $highestRow; $rowNumber++) {
                $summary['total']++;

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

        $attributes['orden_origen'] = $rowNumber;
        $attributes['tipo_plaza'] = TipoPlaza::Eventual->value;
        $attributes['estatus'] = EmpleadoEstatus::Activo->value;

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
     * Same rationale as ImportAgendaZonaOrienteCommand::upsertVacante() —
     * see that command's docblock. No VACANTE rows have been observed in
     * this sheet as of this writing, but the handling is kept for parity
     * and forward-compatibility with the source file's conventions.
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
     * `no_empleado` is unique across the whole `empleados` table (shared
     * with the Base de Datos Zona Oriente sheet's rows), so the same
     * updateOrCreate-by-no_empleado pattern is safe here — `tipo_plaza`
     * in $attributes correctly marks these rows as eventual regardless of
     * which command last touched them.
     *
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
