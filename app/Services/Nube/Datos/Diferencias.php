<?php

namespace App\Services\Nube\Datos;

use App\Models\SeccionArchivo;

/**
 * Lo que hay que hacer para que los documentos de un origen coincidan con la
 * nube. Una entrada de `actualizar` puede traer a la vez cambio de contenido,
 * de nombre/carpeta y restauración (si estaba archivado).
 */
final readonly class Diferencias
{
    /**
     * @param  list<ArchivoRemoto>  $altas
     * @param  list<array{archivo: SeccionArchivo, remoto: ArchivoRemoto, contenido: bool, renombre: bool, restaurar: bool}>  $actualizar
     * @param  list<SeccionArchivo>  $bajas
     */
    public function __construct(
        public array $altas = [],
        public array $actualizar = [],
        public array $bajas = [],
        public int $sinCambios = 0,
        public bool $cambioMasivoRetenido = false,
    ) {}
}
