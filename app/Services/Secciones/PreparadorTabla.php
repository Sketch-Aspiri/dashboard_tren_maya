<?php

namespace App\Services\Secciones;

use App\Support\Texto;

/**
 * Convierte una tabla cruda detectada en un documento (filas sin
 * interpretar) en encabezados + filas de datos, a partir de la fila de
 * encabezados (sugerida automáticamente o elegida por el usuario).
 */
final class PreparadorTabla
{
    /**
     * Cuántas filas iniciales se revisan buscando los encabezados (los
     * Excel institucionales suelen traer títulos/logos arriba).
     */
    private const FILAS_REVISADAS = 15;

    private const MAX_LARGO_ENCABEZADO = 120;

    /**
     * Índice (base 0) de la fila con más celdas de texto (no numéricas):
     * casi siempre la fila de títulos de columna.
     *
     * @param  list<list<mixed>>  $filas
     */
    public function filaEncabezadoSugerida(array $filas): int
    {
        $mejor = 0;
        $mejorPuntaje = -1;

        foreach (array_slice($filas, 0, self::FILAS_REVISADAS) as $indice => $fila) {
            $puntaje = count(array_filter(
                $fila,
                fn ($valor) => is_string($valor) && trim($valor) !== '' && ! is_numeric($valor),
            ));

            if ($puntaje > $mejorPuntaje) {
                $mejor = $indice;
                $mejorPuntaje = $puntaje;
            }
        }

        return $mejor;
    }

    /**
     * Nombres de columna: vacíos -> "Columna N", repetidos -> "Nombre (2)".
     *
     * @param  list<list<mixed>>  $filas
     * @return list<string>
     */
    public function encabezados(array $filas, int $indice): array
    {
        $usados = [];
        $encabezados = [];

        foreach ($filas[$indice] ?? [] as $posicion => $valor) {
            $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
            $nombre = $nombre === '' ? 'Columna '.($posicion + 1) : mb_substr($nombre, 0, self::MAX_LARGO_ENCABEZADO);

            $base = $nombre;
            $repeticion = 2;

            while (isset($usados[Texto::normalizar($nombre)])) {
                $nombre = "{$base} ({$repeticion})";
                $repeticion++;
            }

            $usados[Texto::normalizar($nombre)] = true;
            $encabezados[] = $nombre;
        }

        return $encabezados;
    }

    /**
     * Filas de datos debajo de los encabezados, sin las filas que repiten
     * los encabezados (tablas que se parten en varias páginas).
     *
     * @param  list<list<mixed>>  $filas
     * @return list<list<mixed>>
     */
    public function datos(array $filas, int $indice): array
    {
        $firmaEncabezado = $this->firma($filas[$indice] ?? []);

        return array_values(array_filter(
            array_slice($filas, $indice + 1),
            fn (array $fila) => $this->firma($fila) !== $firmaEncabezado,
        ));
    }

    /**
     * @param  list<mixed>  $fila
     */
    private function firma(array $fila): string
    {
        return implode('|', array_map(fn ($valor) => Texto::normalizar($valor), $fila));
    }
}
