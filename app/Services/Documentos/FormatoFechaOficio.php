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

    /** "18 Sep. 2026" */
    public static function corta(CarbonInterface $fecha): string
    {
        return $fecha->day.' '.ucfirst(self::abreviado($fecha)).' '.$fecha->year;
    }

    /** "18 septiembre 2026" */
    public static function larga(CarbonInterface $fecha): string
    {
        return $fecha->day.' '.self::MESES[$fecha->month].' '.$fecha->year;
    }

    /** "18 septiembre de 2026" */
    public static function largaConDe(CarbonInterface $fecha): string
    {
        return $fecha->day.' '.self::MESES[$fecha->month].' de '.$fecha->year;
    }

    /**
     * Range as printed by the station oficio: "07 AL 19 DE SEPTIEMBRE".
     * Null when neither bound is known.
     */
    public static function rangoLargo(?CarbonInterface $inicio, ?CarbonInterface $fin): ?string
    {
        if ($inicio === null && $fin === null) {
            return null;
        }

        if ($inicio === null || $fin === null) {
            $unico = $inicio ?? $fin;

            return ($inicio !== null ? 'DESDE ' : 'HASTA ').self::diaMesLargo($unico);
        }

        if ($inicio->isSameDay($fin)) {
            return self::diaMesLargo($inicio);
        }

        if ($inicio->month === $fin->month && $inicio->year === $fin->year) {
            return sprintf('%02d AL %02d DE %s', $inicio->day, $fin->day, mb_strtoupper(self::MESES[$fin->month]));
        }

        return self::diaMesLargo($inicio).' AL '.self::diaMesLargo($fin);
    }

    /**
     * Range as printed by the zone oficio: "7 AL 21 SEP. 2026".
     */
    public static function rangoCorto(?CarbonInterface $inicio, ?CarbonInterface $fin): ?string
    {
        if ($inicio === null && $fin === null) {
            return null;
        }

        if ($inicio === null || $fin === null) {
            return mb_strtoupper(self::corta($inicio ?? $fin));
        }

        if ($inicio->isSameDay($fin)) {
            return mb_strtoupper(self::corta($inicio));
        }

        if ($inicio->month === $fin->month && $inicio->year === $fin->year) {
            return $inicio->day.' AL '.mb_strtoupper(self::corta($fin));
        }

        return mb_strtoupper($inicio->day.' '.self::abreviado($inicio).' '.$inicio->year)
            .' AL '.mb_strtoupper(self::corta($fin));
    }

    private static function diaMesLargo(CarbonInterface $fecha): string
    {
        return sprintf('%02d DE %s', $fecha->day, mb_strtoupper(self::MESES[$fecha->month]));
    }

    private static function abreviado(CarbonInterface $fecha): string
    {
        return mb_substr(self::MESES[$fecha->month], 0, 3).'.';
    }
}
