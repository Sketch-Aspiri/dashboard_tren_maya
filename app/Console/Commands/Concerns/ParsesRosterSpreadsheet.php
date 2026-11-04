<?php

namespace App\Console\Commands\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Shared cell-reading, date-parsing, and string-normalization logic for the
 * Jefe de Zona roster spreadsheet importers (app:import-agenda-zona-oriente
 * and app:import-cuadrillas-mantenimiento). Extracted from
 * ImportAgendaZonaOrienteCommand — behavior-preserving refactor, see
 * tests/Feature/Console/ImportAgendaZonaOrienteCommandTest.php.
 *
 * The source file mixes real Excel date cells with free-text Spanish dates
 * (e.g. "26/12/68", "Domingo, 01 de septiembre de 2024"). Slash dates are
 * day/month/year (Mexican convention), matching the unambiguous long-form
 * dates elsewhere in the same file.
 */
trait ParsesRosterSpreadsheet
{
    /**
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
     * Patrones (sobre el encabezado normalizado: minúsculas, sin acentos ni
     * signos) que identifican cada campo en la fila 1 del Excel. Permiten
     * importar archivos a los que se les agregó o quitó una columna sin
     * depender de posiciones fijas.
     *
     * @var array<string, string>
     */
    private const HEADER_PATTERNS = [
        'no_empleado' => '/\bempleado\b/',
        'estacion_codigo' => '/estacion/',
        'plaza_actual' => '/^plaza actual/',
        'nombre_completo' => '/^nombre completo/',
        'puesto' => '/^(puesto|cargo)\b/',
        'nivel_plaza' => '/^nivel de plaza/',
        'ultimo_grado_estudios' => '/ultimo grado/',
        'titulo' => '/^titulo/',
        'cedula' => '/^cedula/',
        'fecha_ingreso' => '/^fecha de ingreso/',
        'telefono' => '/telefon/',
        'correo' => '/^correo/',
        'tipo_sangre' => '/tipo de sangre/',
        'alergias' => '/^alergias/',
        'fecha_nacimiento' => '/^fecha de nacimiento/',
        'lugar_nacimiento' => '/^lugar (de )?nacimiento/',
        'estado_civil' => '/^estado civil/',
        'curp' => '/^curp/',
        'rfc' => '/^rfc/',
        'nss' => '/^nss/',
        'domicilio' => '/^domicilio/',
        'contacto_emergencia_nombre' => '/^contacto de emergencia/',
        'desempeno' => '/^desempeno/',
    ];

    /**
     * Devuelve campo => columna (0-based). Usa los encabezados de la fila 1
     * cuando el archivo los trae completos; si no, el mapa por índice
     * heredado ($legacy).
     *
     * @param  array<string, int>  $legacy
     * @return array<string, int>
     */
    private function resolveColumns(Worksheet $sheet, array $legacy): array
    {
        $found = [];
        $highest = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($col = 1; $col <= $highest; $col++) {
            $text = $this->normalizeHeader($this->safeCellValue($sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')));

            if ($text === '') {
                continue;
            }

            foreach (self::HEADER_PATTERNS as $field => $pattern) {
                if (! isset($found[$field]) && preg_match($pattern, $text) === 1) {
                    $found[$field] = $col - 1;
                    break;
                }
            }
        }

        foreach (['no_empleado', 'nombre_completo', 'curp', 'rfc', 'nss', 'domicilio'] as $required) {
            if (! isset($found[$required])) {
                return $legacy;
            }
        }

        // "Contacto de emergencia" es un encabezado combinado: nombre y
        // teléfono ocupan esa columna y la siguiente.
        if (isset($found['contacto_emergencia_nombre'])) {
            $found['contacto_emergencia_telefono'] = $found['contacto_emergencia_nombre'] + 1;
        }

        return $found;
    }

    /**
     * CURP/RFC/NSS: sin espacios internos y en mayúsculas. Si aun así excede
     * el largo de la columna es un error de captura en el Excel: se guarda
     * vacío (y se registra el campo, sin el dato) en vez de tumbar toda la
     * importación.
     */
    private function normalizeIdentifier(?string $value, string $field = '', int $maxLength = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/\s+/u', '', $value) ?? $value;

        if ($clean === '') {
            return null;
        }

        $clean = mb_strtoupper($clean);

        if ($maxLength > 0 && mb_strlen($clean) > $maxLength) {
            Log::warning('Importación de personal: identificador descartado por exceder el largo permitido.', [
                'campo' => $field,
                'largo' => mb_strlen($clean),
                'maximo' => $maxLength,
            ]);

            return null;
        }

        return $clean;
    }

    private function normalizeHeader(mixed $value): string
    {
        $text = $this->stripAccents(mb_strtolower(trim((string) $value)));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Valor de la celda; si es una fórmula que PhpSpreadsheet no puede
     * calcular (p. ej. conteos de la fila de totales) usa el último valor
     * guardado por Excel, o null, en vez de abortar toda la importación.
     */
    private function safeCellValue(Cell $cell): mixed
    {
        try {
            return $cell->getCalculatedValue();
        } catch (Throwable) {
            try {
                return $cell->getOldCalculatedValue();
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function readCell(Worksheet $sheet, int $rowNumber, int $col0): mixed
    {
        $address = Coordinate::stringFromColumnIndex($col0 + 1).$rowNumber;

        return $this->safeCellValue($sheet->getCell($address));
    }

    private function readDateCell(Worksheet $sheet, int $rowNumber, int $col0): ?string
    {
        $address = Coordinate::stringFromColumnIndex($col0 + 1).$rowNumber;
        $cell = $sheet->getCell($address);
        $rawValue = $this->safeCellValue($cell);

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

        // Espacios Unicode que trim()/\s no cubren (NBSP, U+202F, de ancho cero,
        // BOM): vienen al copiar y pegar en Excel y rompen columnas de largo fijo.
        $string = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', (string) $value) ?? (string) $value;
        $string = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $string) ?? $string;
        $string = trim($string);
        $string = preg_replace('/\s+/u', ' ', $string) ?? $string;

        if ($string === '' || preg_match('/^#[A-Z0-9\/]+[!?]$/', $string) === 1) {
            return null;
        }

        return $string;
    }

    private function isRowEmpty(Worksheet $sheet, int $rowNumber): bool
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $address = Coordinate::stringFromColumnIndex($col).$rowNumber;
            $value = $this->safeCellValue($sheet->getCell($address));

            if ($this->normalizeString($value) !== null) {
                return false;
            }
        }

        return true;
    }
}
