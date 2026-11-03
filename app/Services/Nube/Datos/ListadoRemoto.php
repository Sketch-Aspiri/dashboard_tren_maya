<?php

namespace App\Services\Nube\Datos;

/**
 * Resultado de listar un origen: los archivos sincronizables y lo omitido
 * (con su motivo) para el resumen del origen.
 */
final readonly class ListadoRemoto
{
    /**
     * @param  list<ArchivoRemoto>  $archivos
     * @param  array<string, int>  $omitidos  motivo (MotivoOmision) => cantidad
     */
    public function __construct(
        public array $archivos,
        public array $omitidos = [],
    ) {}

    /**
     * Cuántos de los archivos son hojas de cálculo (xlsx, xls, csv).
     */
    public function excel(): int
    {
        return count(array_filter(
            $this->archivos,
            fn (ArchivoRemoto $archivo): bool => in_array(strtolower(pathinfo($archivo->nombre, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'csv'], true),
        ));
    }
}
