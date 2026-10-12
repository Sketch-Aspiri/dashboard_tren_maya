<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Plegado de texto para comparar sin importar mayúsculas ni acentos
 * ("Chetumal" == "CHETUMAL", "Estación" == "estacion"). Misma regla que
 * normalizarTexto() en resources/js/app.js.
 */
final class Texto
{
    public static function normalizar(mixed $valor): string
    {
        if ($valor === null || is_array($valor)) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? 'si' : 'no';
        }

        $texto = Str::lower(Str::ascii(trim((string) $valor)));

        return (string) preg_replace('/\s+/u', ' ', $texto);
    }
}
