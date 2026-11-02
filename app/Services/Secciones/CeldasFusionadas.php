<?php

namespace App\Services\Secciones;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Expande fusiones de Excel: PhpSpreadsheet solo guarda el valor en la
 * celda maestra (arriba-izquierda); las demás del rango llegan vacías.
 * Sin este relleno, las filas de continuación (p. ej. varias FECHA bajo
 * un mismo Estación/siniestro) se importan o previsualizan con "—".
 */
final class CeldasFusionadas
{
    /**
     * @param  list<list<mixed>>  $filas
     * @return list<list<mixed>>
     */
    public function rellenar(array $filas, Worksheet $hoja): array
    {
        if ($filas === []) {
            return $filas;
        }

        $maxFila = count($filas);
        $maxColumna = count($filas[0]);

        foreach ($hoja->getMergeCells() as $rango) {
            [$inicio, $fin] = Coordinate::rangeBoundaries($rango);
            $colInicio = (int) $inicio[0];
            $filaInicio = (int) $inicio[1];
            $colFin = (int) $fin[0];
            $filaFin = (int) $fin[1];

            if ($filaInicio < 1 || $filaInicio > $maxFila || $colInicio < 1 || $colInicio > $maxColumna) {
                continue;
            }

            $maestro = $filas[$filaInicio - 1][$colInicio - 1] ?? null;
            if ($maestro === null || $maestro === '') {
                continue;
            }

            for ($fila = $filaInicio; $fila <= min($filaFin, $maxFila); $fila++) {
                for ($columna = $colInicio; $columna <= min($colFin, $maxColumna); $columna++) {
                    if ($fila === $filaInicio && $columna === $colInicio) {
                        continue;
                    }

                    $actual = $filas[$fila - 1][$columna - 1] ?? null;
                    if ($actual === null || $actual === '') {
                        $filas[$fila - 1][$columna - 1] = $maestro;
                    }
                }
            }
        }

        return $filas;
    }
}
