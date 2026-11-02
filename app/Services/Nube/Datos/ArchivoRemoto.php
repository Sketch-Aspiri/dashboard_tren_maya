<?php

namespace App\Services\Nube\Datos;

/**
 * Un archivo tal como lo ve el proveedor de nube. `version` es el token de
 * contenido del proveedor (cTag, md5, content_hash); null cuando el proveedor
 * no lo da por adelantado (enlace público: solo se sabe al descargar).
 */
final readonly class ArchivoRemoto
{
    public function __construct(
        public string $id,
        public string $nombre,
        public string $rutaRelativa,
        public ?int $tamano = null,
        public ?string $version = null,
    ) {}
}
