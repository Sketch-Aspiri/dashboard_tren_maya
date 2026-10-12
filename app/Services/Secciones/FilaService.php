<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\User;

/**
 * Alta, edición y baja manual de filas de la tabla de una sección. Cada
 * operación queda en la bitácora vía LogsActivity de SeccionFila.
 */
final class FilaService
{
    public function __construct(private readonly NormalizadorValores $normalizador) {}

    /**
     * @param  array<string, mixed>  $valores  indexado por clave de columna (ya validado)
     */
    public function crear(Seccion $seccion, array $valores, User $usuario): SeccionFila
    {
        $fila = new SeccionFila(['datos' => $this->normalizar($seccion, $valores, [])]);
        $fila->forceFill([
            'seccion_id' => $seccion->id,
            'creado_por' => $usuario->id,
            'actualizado_por' => $usuario->id,
        ])->save();

        return $fila;
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    public function actualizar(SeccionFila $fila, array $valores, User $usuario): SeccionFila
    {
        $fila->datos = $this->normalizar($fila->seccion, $valores, $fila->datos ?? []);
        $fila->forceFill(['actualizado_por' => $usuario->id])->save();

        return $fila;
    }

    public function eliminar(SeccionFila $fila): void
    {
        $fila->delete();
    }

    /**
     * Solo se guardan claves de columnas existentes; los valores de
     * columnas que no vienen en el formulario se conservan.
     *
     * @param  array<string, mixed>  $valores
     * @param  array<string, mixed>  $actuales
     * @return array<string, mixed>
     */
    private function normalizar(Seccion $seccion, array $valores, array $actuales): array
    {
        $datos = $actuales;

        $seccion->columnas->each(function (SeccionColumna $columna) use ($valores, &$datos): void {
            if (array_key_exists($columna->clave, $valores)) {
                $datos[$columna->clave] = $this->normalizador->normalizar($valores[$columna->clave], $columna->tipo);
            }
        });

        return $datos;
    }
}
