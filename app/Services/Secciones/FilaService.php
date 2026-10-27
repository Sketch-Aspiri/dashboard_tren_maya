<?php

namespace App\Services\Secciones;

use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Alta, edición y baja manual de filas de una tabla de una sección. Cada
 * operación queda en la bitácora vía LogsActivity de SeccionFila.
 */
final class FilaService
{
    public function __construct(
        private readonly NormalizadorValores $normalizador,
        private readonly FotoService $fotos,
    ) {}

    /**
     * @param  array<string, mixed>  $valores  indexado por clave de columna (ya validado)
     * @param  array<string, UploadedFile>  $fotos  archivos subidos, por clave de columna Foto
     */
    public function crear(SeccionTabla $tabla, array $valores, User $usuario, array $fotos = []): SeccionFila
    {
        $fila = new SeccionFila(['datos' => $this->normalizar($tabla, $valores + $this->guardarFotos($fotos), [])]);
        $fila->forceFill([
            'seccion_id' => $tabla->seccion_id,
            'seccion_tabla_id' => $tabla->id,
            'creado_por' => $usuario->id,
            'actualizado_por' => $usuario->id,
        ])->save();

        return $fila;
    }

    /**
     * @param  array<string, mixed>  $valores
     * @param  array<string, UploadedFile>  $fotos
     */
    public function actualizar(SeccionFila $fila, array $valores, User $usuario, array $fotos = []): SeccionFila
    {
        $fila->datos = $this->normalizar($fila->tabla, $valores + $this->guardarFotos($fotos), $fila->datos ?? []);
        $fila->forceFill(['actualizado_por' => $usuario->id])->save();

        return $fila;
    }

    public function eliminar(SeccionFila $fila): void
    {
        $fila->delete();
    }

    /**
     * @param  array<string, UploadedFile>  $fotos
     * @return array<string, string> clave de columna => nombre de archivo guardado
     */
    private function guardarFotos(array $fotos): array
    {
        $guardadas = [];

        foreach ($fotos as $clave => $archivo) {
            $nombre = $this->fotos->guardar((string) file_get_contents($archivo->getRealPath()));

            if ($nombre === null) {
                throw ValidationException::withMessages(['fotos.'.$clave => __('No se pudo leer la imagen.')]);
            }

            $guardadas[$clave] = $nombre;
        }

        return $guardadas;
    }

    /**
     * Solo se guardan claves de columnas existentes; los valores de
     * columnas que no vienen en el formulario se conservan.
     *
     * @param  array<string, mixed>  $valores
     * @param  array<string, mixed>  $actuales
     * @return array<string, mixed>
     */
    private function normalizar(SeccionTabla $tabla, array $valores, array $actuales): array
    {
        $datos = $actuales;

        $tabla->columnas->each(function (SeccionColumna $columna) use ($valores, &$datos): void {
            if (array_key_exists($columna->clave, $valores)) {
                $datos[$columna->clave] = $this->normalizador->normalizar($valores[$columna->clave], $columna->tipo);
            }
        });

        return $datos;
    }
}
