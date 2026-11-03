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
}
