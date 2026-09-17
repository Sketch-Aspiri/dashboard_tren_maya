<?php

namespace App\Console\Commands\Concerns;

use Carbon\Carbon;
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
}
