<?php

namespace App\Support;

/**
 * Estilo de una celda de la hoja de cálculo (seccion_filas.formato[clave]).
 * Solo se guardan estas claves, con valores de una lista fija o un color
 * #rrggbb: nada de CSS libre (el navegador lo aplica con
 * element.style.setProperty, nunca concatenando HTML).
 *
 * n = negrita, k = cursiva, s = subrayado, t = tachado, a = ajustar texto,
 * color = color de texto, fondo = color de relleno, alin = alineación,
 * tam = tamaño de letra (pt).
 */
final class EstiloCelda
{
    public const BANDERAS = ['n', 'k', 's', 't', 'a'];

    public const ALINEACIONES = ['izq', 'centro', 'der'];

    public const TAMANOS = [9, 10, 11, 12, 14, 16, 18, 20, 24];

    public const PATRON_COLOR = '/^#[0-9a-f]{6}$/i';

    /** Estilos de "formato como tabla" (encabezado y filas alternadas). */
    public const ESTILOS_TABLA = [
        'verde' => ['encabezado' => '#0f5132', 'banda' => '#e8f3ee'],
        'azul' => ['encabezado' => '#1e3a8a', 'banda' => '#e8eefb'],
        'gris' => ['encabezado' => '#374151', 'banda' => '#f1f2f4'],
        'ambar' => ['encabezado' => '#92400e', 'banda' => '#fdf3e1'],
    ];

    /**
     * Reglas de validación para un estilo bajo el prefijo dado
     * (p. ej. "cambios.*.formato").
     *
     * @return array<string, list<mixed>>
     */
    public static function reglas(string $prefijo): array
    {
        $reglas = [$prefijo => ['nullable', 'array']];

        foreach (self::BANDERAS as $bandera) {
            $reglas["{$prefijo}.{$bandera}"] = ['nullable', 'boolean'];
        }

        return $reglas + [
            "{$prefijo}.color" => ['nullable', 'string', 'regex:'.self::PATRON_COLOR],
            "{$prefijo}.fondo" => ['nullable', 'string', 'regex:'.self::PATRON_COLOR],
            "{$prefijo}.alin" => ['nullable', 'in:'.implode(',', self::ALINEACIONES)],
            "{$prefijo}.tam" => ['nullable', 'integer', 'in:'.implode(',', self::TAMANOS)],
        ];
    }

    /**
     * Solo las claves conocidas y con valor útil; null si queda vacío (la
     * celda vuelve al estilo normal). Descarta en silencio lo que no sea
     * válido (la validación ya lo rechazó antes en las peticiones).
     *
     * @param  array<string, mixed>|null  $estilo
     * @return array<string, bool|int|string>|null
     */
    public static function limpiar(?array $estilo): ?array
    {
        if ($estilo === null) {
            return null;
        }

        $limpio = [];

        foreach (self::BANDERAS as $bandera) {
            if (filter_var($estilo[$bandera] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $limpio[$bandera] = true;
            }
        }

        foreach (['color', 'fondo'] as $clave) {
            $color = is_string($estilo[$clave] ?? null) ? strtolower($estilo[$clave]) : null;

            if ($color !== null && preg_match(self::PATRON_COLOR, $color) === 1) {
                $limpio[$clave] = $color;
            }
        }

        if (in_array($estilo['alin'] ?? null, self::ALINEACIONES, true)) {
            $limpio['alin'] = $estilo['alin'];
        }

        $tamano = isset($estilo['tam']) && is_numeric($estilo['tam']) ? (int) $estilo['tam'] : null;

        if ($tamano !== null && in_array($tamano, self::TAMANOS, true)) {
            $limpio['tam'] = $tamano;
        }

        return $limpio === [] ? null : $limpio;
    }
}
