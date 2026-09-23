<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesRosterSpreadsheet;
use App\Console\Commands\Concerns\ResolvesEstacionesPorNombre;
use App\Models\EstatusViaAnden;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Importa la hoja de "Esquema de vías / andenes" (hoja visible "ZO_<MES>_<AA>")
 * del mismo ANEXO que app:import-controles hacia estatus_vias_andenes
 * (módulo "Controles" -> "Estatus de vías y andenes"). Las hojas ocultas de
 * meses anteriores ("ZO_DIC_06", ...) se ignoran: solo cuenta la visible.
 *
 * Cada hoja tiene una fila de encabezados y luego una fila por vía; la
 * columna "Estación" solo lleva valor en la primera fila de cada estación
 * (celdas combinadas) — las filas siguientes heredan la última estación no
 * vacía leída. Las imágenes del "Esquema de Vías" no se importan.
 *
 * Reemplaza el contenido completo de la tabla en cada corrida (delete +
 * insertar de nuevo): el origen no tiene un identificador estable por fila.
 */
class ImportEstatusViasAndenesCommand extends Command
{
    use ParsesRosterSpreadsheet, ResolvesEstacionesPorNombre;

    private const DEFAULT_IMPORT_DIR = 'private/imports/controles/escaleras y elevadores';

    private const HOJA_PREFIJO = 'ZO_';

    private const FILA_ENCABEZADOS = 3;

    // 0-based column indexes: C, E, F, G, H, I, J, K, L, M.
    private const COL_ESTACION = 2;

    private const COL_VIA = 4;

    private const COL_ANDEN = 5;

    private const COL_ESTATUS_ANDEN = 6;

    private const COL_ESTATUS_VIA = 7;

    private const COL_SENALETICA = 8;

    private const COL_TELEINDICADORES = 9;

    private const COL_PRUEBAS_GALIBO = 10;

    private const COL_RIESGOS = 11;

    private const COL_COMENTARIOS = 12;

    /**
     * "Nicolás Bravo" en el Excel es la misma estación física que el
     * catálogo llama "Kohunlich" (mismo alias que los demás importadores;
     * el catálogo nunca se renombra).
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'NICOLAS BRAVO' => 'Kohunlich',
    ];

    /** @var list<string> */
    private array $estacionesNoReconocidas = [];

    protected $signature = 'app:import-estatus-vias-andenes
        {path? : Ruta al archivo .xlsx (por defecto storage/app/private/imports/controles/escaleras y elevadores/*.xlsx)}';

    protected $description = 'Importa el estatus de vías y andenes (hoja ZO_*) hacia estatus_vias_andenes.';

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

        $hoja = $this->hojaVisibleConPrefijo($spreadsheet);

        if ($hoja === null) {
            $this->components->error('El archivo no tiene una hoja visible "'.self::HOJA_PREFIJO.'*".');

            return self::FAILURE;
        }

        $this->estacionesNoReconocidas = [];
        $total = 0;

        DB::transaction(function () use ($hoja, &$total) {
            $total = $this->importarHoja($hoja);
        });

        $this->renderSummary($hoja->getTitle(), $total);

        return self::SUCCESS;
    }

    private function defaultPath(): ?string
    {
        $directory = storage_path('app/'.self::DEFAULT_IMPORT_DIR);

        return (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [])[0] ?? null;
    }

    private function hojaVisibleConPrefijo(Spreadsheet $spreadsheet): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && str_starts_with(mb_strtoupper($sheet->getTitle()), self::HOJA_PREFIJO)) {
                return $sheet;
            }
        }

        return null;
    }

    private function importarHoja(Worksheet $sheet): int
    {
        // delete(), not truncate(): TRUNCATE causes an implicit commit on
        // MySQL, which would silently break the surrounding DB::transaction().
        EstatusViaAnden::query()->delete();

        $estaciones = $this->estacionesPorNombreNormalizadoDescendente();
        $estacionActual = null;
        $total = 0;

        for ($row = self::FILA_ENCABEZADOS + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $nombreCelda = $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTACION));

            if ($nombreCelda !== null) {
                $normalizado = $this->normalizeHeader($nombreCelda);
                $estacionActual = $normalizado === null ? null : $this->resolverEstacion($normalizado, $estaciones, self::ALIAS);

                // An unrecognized estación resets the tracker rather than
                // keeping the previous one — better to skip its rows than
                // silently attribute them to the wrong estación.
                if ($estacionActual === null) {
                    $this->estacionesNoReconocidas[] = $nombreCelda;
                }
            }

            $via = $this->numeroDeVia($this->readCell($sheet, $row, self::COL_VIA));

            if ($via === null || $estacionActual === null) {
                continue;
            }

            EstatusViaAnden::query()->create([
                'estacion_id' => $estacionActual->id,
                'via' => $via,
                'anden' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ANDEN)),
                'estatus_anden' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTATUS_ANDEN)),
                'estatus_via' => $this->normalizeString($this->readCell($sheet, $row, self::COL_ESTATUS_VIA)),
                'senaletica' => $this->normalizeString($this->readCell($sheet, $row, self::COL_SENALETICA)),
                'teleindicadores' => $this->normalizeString($this->readCell($sheet, $row, self::COL_TELEINDICADORES)),
                'pruebas_galibo' => $this->normalizeString($this->readCell($sheet, $row, self::COL_PRUEBAS_GALIBO)),
                'riesgos_obstaculos' => $this->textoConSaltos($this->readCell($sheet, $row, self::COL_RIESGOS)),
                'comentarios' => $this->textoConSaltos($this->readCell($sheet, $row, self::COL_COMENTARIOS)),
            ]);
            $total++;
        }

        return $total;
    }

    private function numeroDeVia(mixed $valor): ?int
    {
        $texto = $this->normalizeString($valor);

        return $texto !== null && preg_match('/^\d{1,2}$/', $texto) === 1 ? (int) $texto : null;
    }

    /**
     * Free-text notes keep their paragraph breaks (unlike normalizeString,
     * which flattens all whitespace) — same helper as ImportControlesCommand.
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

    private function renderSummary(string $hoja, int $total): void
    {
        $this->components->info('Importación de Estatus de vías y andenes completada.');

        $this->table(
            ['Hoja', 'Vías importadas', 'Estaciones no reconocidas'],
            [[$hoja, $total, count($this->estacionesNoReconocidas)]],
        );

        if ($this->estacionesNoReconocidas !== []) {
            $this->components->warn('Estaciones no reconocidas (omitidas): '.implode('; ', array_unique($this->estacionesNoReconocidas)));
        }
    }
}
