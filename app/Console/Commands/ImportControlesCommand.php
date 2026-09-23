<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Console\Commands\Concerns\ResolvesEstacionesPorNombre;
use App\Models\Elevador;
use App\Models\EscaleraElectrica;
use App\Models\Estacion;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Importa el ANEXO "Escaleras eléctricas, Elevadores, Esquemas activos"
 * hacia escaleras_electricas + elevadores (módulo "Controles"). Solo se
 * leen las hojas "ESCALERAS ELECTRICAS-*" y "ELEVADORES-*" — la hoja de
 * esquema de vías/andenes ("ZO_*") la carga app:import-estatus-vias-andenes.
 *
 * Cada hoja tiene una fila de encabezados y luego una fila por equipo; la
 * columna "Estación" solo lleva valor en la primera fila de cada estación
 * (celdas combinadas visualmente) — las filas siguientes heredan la última
 * estación no vacía leída.
 *
 * Reemplaza el contenido completo de ambas tablas en cada corrida
 * (truncate + insertar de nuevo) en vez de updateOrCreate por fila: el
 * origen no tiene un identificador estable por equipo (varias filas del
 * mismo modelo, sin "ID Escalera"/"ID Elevador"), así que no hay una llave
 * natural para hacer upsert.
 */
class ImportControlesCommand extends Command
{
    use ParsesRosterSpreadsheet, ResolvesEstacionesPorNombre;

    private const DEFAULT_IMPORT_DIR = 'private/imports/controles/escaleras y elevadores';

    private const HOJA_ESCALERAS_PREFIJO = 'ESCALERAS ELECTRICAS';

    private const HOJA_ELEVADORES_PREFIJO = 'ELEVADORES';

    private const FILA_ENCABEZADOS = 3;

    // 0-based column indexes, comunes a ambas hojas.
    private const COL_ESTACION = 1;

    private const COL_ID = 2;

    private const COL_MODELO = 3;

    private const COL_ANIO = 4;

    private const COL_TIPO = 5;

    private const COL_OPERATIVO = 6;

    // Escaleras eléctricas: H, I, J, K.
    private const COL_ESCALERA_BARANDALES = 7;

    private const COL_ESCALERA_BOTON_PARO = 8;

    private const COL_ESCALERA_FECHA_MANTENIMIENTO = 9;

    private const COL_ESCALERA_OBSERVACIONES = 10;

    // Elevadores: H, I, J.
    private const COL_ELEVADOR_FECHA_MANTENIMIENTO = 7;

    private const COL_ELEVADOR_ESTADO_PUERTAS = 8;

    private const COL_ELEVADOR_OBSERVACIONES = 9;

    /**
     * Rango razonable para "Año de instalación" y para el año resuelto de
     * "Fecha de último mantenimiento" — descarta valores claramente
     * corruptos del origen (p. ej. "21/01/202", con un dígito faltante del
     * año) en vez de guardarlos como si fueran válidos.
     */
    private const ANIO_MINIMO = 2000;

    private const ANIO_MAXIMO = 2100;

    /** @var array{estaciones: list<string>, fechas: list<string>} */
    private array $noReconocidos;

    protected $signature = 'app:import-controles
        {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/controles/escaleras y elevadores/*.xlsx)}';

    protected $description = 'Importa el inventario de escaleras eléctricas y elevadores hacia escaleras_electricas y elevadores.';

    public function handle(): int
    {
        $path = $this->argument('path') ?? $this->defaultPath();

        if ($path === null) {
            $this->components->error('No se encontró ningún archivo .xlsx para importar en storage/app/'.self::DEFAULT_IMPORT_DIR.'.');

            return self::FAILURE;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            $this->components->error('No fue posible leer el archivo: '.$exception->getMessage());

            return self::FAILURE;
        }

        $hojaEscaleras = $this->hojaConPrefijo($spreadsheet, self::HOJA_ESCALERAS_PREFIJO);
        $hojaElevadores = $this->hojaConPrefijo($spreadsheet, self::HOJA_ELEVADORES_PREFIJO);

        if ($hojaEscaleras === null || $hojaElevadores === null) {
            $this->components->error('El archivo no tiene las hojas "'.self::HOJA_ESCALERAS_PREFIJO.'-*" y "'.self::HOJA_ELEVADORES_PREFIJO.'-*".');

            return self::FAILURE;
        }

        $this->noReconocidos = ['estaciones' => [], 'fechas' => []];

        $totalEscaleras = 0;
        $totalElevadores = 0;

        DB::transaction(function () use ($hojaEscaleras, $hojaElevadores, &$totalEscaleras, &$totalElevadores) {
            $estaciones = $this->estacionesPorNombreNormalizadoDescendente();

            $totalEscaleras = $this->importarHoja($hojaEscaleras, $estaciones, EscaleraElectrica::class, fn (Worksheet $sheet, int $row, Estacion $estacion) => $this->filaDeEscalera($sheet, $row, $estacion));
            $totalElevadores = $this->importarHoja($hojaElevadores, $estaciones, Elevador::class, fn (Worksheet $sheet, int $row, Estacion $estacion) => $this->filaDeElevador($sheet, $row, $estacion));
        });

        $this->renderSummary($totalEscaleras, $totalElevadores);

        return self::SUCCESS;
    }

    private function defaultPath(): ?string
    {
        $directory = storage_path('app/'.self::DEFAULT_IMPORT_DIR);

        return (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [])[0] ?? null;
    }

    private function hojaConPrefijo(Spreadsheet $spreadsheet, string $prefijo): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && str_starts_with(mb_strtoupper($sheet->getTitle()), $prefijo)) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @param  array<string, Estacion>  $estaciones
     * @param  class-string<Model>  $modelClass
     * @param  Closure(Worksheet, int, Estacion): array<string, mixed>  $filaCallback
     */
    private function importarHoja(Worksheet $sheet, array $estaciones, string $modelClass, Closure $filaCallback): int
    {
        // delete(), not truncate(): TRUNCATE causes an implicit commit on
        // MySQL, which would silently break the DB::transaction() wrapping
        // both hojas below.
        $modelClass::query()->delete();

        $estacionActual = null;
        $total = 0;

        for ($row = self::FILA_ENCABEZADOS + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $nombreCelda = $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTACION));

            if ($nombreCelda !== null) {
                $normalizado = $this->normalizeHeader($nombreCelda);
                $resuelta = $normalizado === null ? null : $this->resolverEstacion($normalizado, $estaciones);

                if ($resuelta === null) {
                    $this->noReconocidos['estaciones'][] = $nombreCelda;
                }

                // A row with an unrecognized estación name resets the
                // "current" tracker to null rather than keeping the
                // previous one — better to skip the following equipment
                // rows than silently attribute them to the wrong estación.
                $estacionActual = $resuelta;
            }

            if (! $this->filaTieneDatosDeEquipo($sheet, $row) || $estacionActual === null) {
                continue;
            }

            $modelClass::query()->create($filaCallback($sheet, $row, $estacionActual));
            $total++;
        }

        return $total;
    }

    /**
     * Una fila tiene datos de equipo si alguna columna, además de
     * "Estación", no está vacía.
     */
    private function filaTieneDatosDeEquipo(Worksheet $sheet, int $row): bool
    {
        foreach ([self::COL_ID, self::COL_MODELO, self::COL_ANIO, self::COL_TIPO, self::COL_OPERATIVO] as $col) {
            if ($this->normalizeString($this->readCell($sheet, $row, $col)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function filaDeEscalera(Worksheet $sheet, int $row, Estacion $estacion): array
    {
        return [
            'estacion_id' => $estacion->id,
            'identificador' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ID)),
            'modelo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_MODELO)),
            'anio_instalacion' => $this->anioValido($this->readCell($sheet, $row, self::COL_ANIO)),
            'tipo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_TIPO)),
            'operativo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_OPERATIVO)),
            'estado_barandales' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ESCALERA_BARANDALES)),
            'estado_boton_paro_emergencia' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ESCALERA_BOTON_PARO)),
            'fecha_ultimo_mantenimiento' => $this->resolverFecha($sheet, $row, self::COL_ESCALERA_FECHA_MANTENIMIENTO),
            'observaciones' => $this->textoConSaltos($this->readCell($sheet, $row, self::COL_ESCALERA_OBSERVACIONES)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filaDeElevador(Worksheet $sheet, int $row, Estacion $estacion): array
    {
        return [
            'estacion_id' => $estacion->id,
            'identificador' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ID)),
            'modelo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_MODELO)),
            'anio_instalacion' => $this->anioValido($this->readCell($sheet, $row, self::COL_ANIO)),
            'tipo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_TIPO)),
            'operativo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_OPERATIVO)),
            'fecha_ultimo_mantenimiento' => $this->resolverFecha($sheet, $row, self::COL_ELEVADOR_FECHA_MANTENIMIENTO),
            'estado_puertas_cabina_botoneras' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ELEVADOR_ESTADO_PUERTAS)),
            'observaciones' => $this->textoConSaltos($this->readCell($sheet, $row, self::COL_ELEVADOR_OBSERVACIONES)),
        ];
    }

    private function anioValido(mixed $valor): ?int
    {
        $texto = $this->normalizeString($valor);

        if ($texto === null || preg_match('/^\d{4}$/', $texto) !== 1) {
            return null;
        }

        $anio = (int) $texto;

        return $anio >= self::ANIO_MINIMO && $anio <= self::ANIO_MAXIMO ? $anio : null;
    }

    /**
     * A diferencia de readDateCell() (usado por los otros importadores),
     * aquí las celdas de fecha llegan sin formato de fecha en Excel (se ven
     * como el número de serie crudo, p. ej. "45985"), así que un valor
     * numérico se trata siempre como serie de Excel en vez de depender del
     * formato de la celda. Cualquier año fuera de rango — típicamente un
     * dígito faltante del origen, como "21/01/202" — se descarta en vez de
     * guardarse como una fecha absurda.
     */
    private function resolverFecha(Worksheet $sheet, int $row, int $col0): ?string
    {
        $raw = $this->readCell($sheet, $row, $col0);
        $texto = $this->normalizeString($raw);

        if ($texto === null) {
            return null;
        }

        $fecha = is_numeric($raw)
            ? $this->carbonFromExcelSerial((float) $raw)
            : $this->carbonFromString($texto);

        if ($fecha === null) {
            $this->noReconocidos['fechas'][] = $texto;

            return null;
        }

        $anio = (int) substr($fecha, 0, 4);

        if ($anio < self::ANIO_MINIMO || $anio > self::ANIO_MAXIMO) {
            $this->noReconocidos['fechas'][] = $texto;

            return null;
        }

        return $fecha;
    }

    /**
     * Free-text notes keep their paragraph breaks (unlike normalizeString,
     * which flattens all whitespace) — same helper as
     * ImportGastoEnergeticoCommand.
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

    private function renderSummary(int $totalEscaleras, int $totalElevadores): void
    {
        $this->components->info('Importación de Controles completada.');

        $this->table(
            ['Escaleras eléctricas', 'Elevadores', 'Estaciones no reconocidas', 'Fechas no reconocidas'],
            [[
                $totalEscaleras,
                $totalElevadores,
                count($this->noReconocidos['estaciones']),
                count($this->noReconocidos['fechas']),
            ]],
        );

        foreach (['estaciones' => 'Estaciones no reconocidas (omitidas)', 'fechas' => 'Fechas que no se pudieron leer (omitidas)'] as $clave => $titulo) {
            if ($this->noReconocidos[$clave] !== []) {
                $this->components->warn($titulo.': '.implode('; ', array_unique($this->noReconocidos[$clave])));
            }
        }
    }
}
