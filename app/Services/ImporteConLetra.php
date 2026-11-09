<?php

namespace App\Services;

/**
 * Importe con letra en pesos mexicanos, como lo lleva la hoja FOLIOS:
 * "Dos mil setecientos quince pesos 11/100 M.N.".
 */
final class ImporteConLetra
{
    private const UNIDADES = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
        'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const CENTENAS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    public static function convertir(float $monto): string
    {
        $monto = round(abs($monto), 2);
        $enteros = (int) floor($monto);
        $centavos = (int) round(($monto - $enteros) * 100);

        $texto = $enteros === 0 ? 'cero' : self::apocope(self::numero($enteros));
        $moneda = $enteros === 1 ? 'peso' : (($enteros % 1000000 === 0 && $enteros > 0) ? 'de pesos' : 'pesos');

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1).' '.$moneda.' '.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT).'/100 M.N.';
    }

    /**
     * "uno" y "veintiuno" pierden la o delante de un sustantivo o de mil/millones:
     * un peso, veintiún mil, cincuenta y un pesos.
     */
    private static function apocope(string $texto): string
    {
        return (string) preg_replace(['/veintiuno$/u', '/uno$/u'], ['veintiún', 'un'], $texto);
    }

    private static function numero(int $n): string
    {
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;
            $texto = ($millones === 1 ? 'un millón' : self::apocope(self::numero($millones)).' millones');

            return $resto === 0 ? $texto : $texto.' '.self::numero($resto);
        }

        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $texto = $miles === 1 ? 'mil' : self::apocope(self::numero($miles)).' mil';

            return $resto === 0 ? $texto : $texto.' '.self::numero($resto);
        }

        return self::centenas($n);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'cien';
        }

        $texto = '';

        if ($n >= 100) {
            $texto = self::CENTENAS[intdiv($n, 100)];
            $n %= 100;
        }

        if ($n === 0) {
            return $texto;
        }

        $resto = $n < 30 ? self::UNIDADES[$n] : self::DECENAS[intdiv($n, 10)].($n % 10 === 0 ? '' : ' y '.self::UNIDADES[$n % 10]);

        // "un" delante de mil/millón ("veintiún mil") se maneja con apócope en numero().
        return trim($texto.' '.$resto);
    }
}
