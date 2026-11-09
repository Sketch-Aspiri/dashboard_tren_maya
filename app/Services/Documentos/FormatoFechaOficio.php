<?php

namespace App\Services\Documentos;

use Carbon\CarbonInterface;

/**
 * Spanish date formats used inside the attendance oficios. Deliberately not
 * built on Carbon's locale/translatedFormat so the output never depends on
 * the app locale or the server's ICU data.
 */
final class FormatoFechaOficio
{
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** "8 Nov. 2026" */
    public static function corta(CarbonInterface $fecha): string
    {
        return $fecha->day.' '.ucfirst(self::abreviado($fecha)).' '.$fecha->year;
    }

    /**
     * Period as the Excel prints it under a status (always two-digit days):
     * "08 NOV. 2026", "07 AL 19 SEP. 2026", "29 SEP. AL 02 OCT. 2026",
     * "28 DIC. 2026 AL 03 ENE. 2027". With one bound only, that day alone.
     * Null when neither bound is known.
     */
    public static function rangoCorto(?CarbonInterface $inicio, ?CarbonInterface $fin): ?string
    {
        if ($inicio === null && $fin === null) {
            return null;
        }

        $inicio ??= $fin;
        $fin ??= $inicio;

        if ($inicio->isSameDay($fin)) {
            return self::diaMesAnio($inicio);
        }

        if ($inicio->year === $fin->year && $inicio->month === $fin->month) {
            return sprintf('%02d AL %s', $inicio->day, self::diaMesAnio($fin));
        }

        if ($inicio->year === $fin->year) {
            return self::diaMes($inicio).' AL '.self::diaMesAnio($fin);
        }

        return self::diaMesAnio($inicio).' AL '.self::diaMesAnio($fin);
    }

    private static function diaMes(CarbonInterface $fecha): string
    {
        return sprintf('%02d %s', $fecha->day, mb_strtoupper(self::abreviado($fecha)));
    }

    private static function diaMesAnio(CarbonInterface $fecha): string
    {
        return self::diaMes($fecha).' '.$fecha->year;
    }

    private static function abreviado(CarbonInterface $fecha): string
    {
        return mb_substr(self::MESES[$fecha->month], 0, 3).'.';
    }
}
