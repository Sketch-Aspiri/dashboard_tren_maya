<?php

namespace App\Support;

/**
 * Filtro de datos personales antes de que cualquier información salga del
 * servidor hacia la Claude API (asistente). Dos capas:
 *  - columnas cuyo nombre indica un dato personal sensible no se entregan;
 *  - todo texto se redacta por patrón (CURP, RFC, NSS, teléfonos, correos,
 *    CLABE/tarjetas), por si el dato viene en una columna o documento con
 *    otro nombre.
 */
final class DatosSensibles
{
    public const REDACTADO = '[dato personal omitido]';

    /** Palabras (ya normalizadas: minúsculas, sin acentos) que marcan una columna sensible. */
    private const PALABRAS_COLUMNA = [
        'curp', 'rfc', 'nss', 'imss', 'issste', 'seguro social', 'domicilio', 'direccion', 'telefono', 'tel', 'celular',
        'movil', 'correo', 'email', 'e-mail', 'sangre', 'alergia', 'alergias', 'salud', 'medico', 'medica', 'diagnostico',
        'enfermedad', 'clabe', 'cuenta bancaria', 'tarjeta', 'contrasena', 'password', 'ine', 'pasaporte',
        'fecha de nacimiento', 'nacimiento', 'contacto de emergencia', 'emergencia',
    ];

    /** @var array<string, string> patrón => reemplazo */
    private const PATRONES = [
        // CURP: 4 letras, 6 dígitos (fecha), H/M, 5 letras, 1 alfanumérico, 1 dígito.
        '/\b[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d\b/i' => self::REDACTADO,
        // RFC de persona física o moral.
        '/\b[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}\b/iu' => self::REDACTADO,
        // Correos electrónicos.
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => self::REDACTADO,
        // CLABE (18), tarjetas (15-16), NSS (11) y teléfonos (10), con o sin separadores.
        '/\b\d(?:[ -]?\d){17}\b/' => self::REDACTADO,
        '/\b\d(?:[ -]?\d){14,15}\b/' => self::REDACTADO,
        '/\b\d{11}\b/' => self::REDACTADO,
        '/(?<!\d)(?:\+?52[ -]?)?(?:\(?\d{2,3}\)?[ -]?)\d{3,4}[ -]?\d{4}(?!\d)/' => self::REDACTADO,
    ];

    public static function esColumnaSensible(string $nombre): bool
    {
        $normalizado = ' '.preg_replace('/[^a-z0-9]+/', ' ', Texto::normalizar($nombre)).' ';

        foreach (self::PALABRAS_COLUMNA as $palabra) {
            if (str_contains($normalizado, ' '.$palabra.' ')) {
                return true;
            }
        }

        return false;
    }

    public static function redactar(string $texto): string
    {
        return (string) preg_replace(array_keys(self::PATRONES), array_values(self::PATRONES), $texto);
    }
}
