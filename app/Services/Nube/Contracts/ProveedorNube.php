<?php

namespace App\Services\Nube\Contracts;

use App\Models\SeccionOrigen;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Secciones\NubeSyncException;

/**
 * Lo que el motor de sincronización necesita de una nube. Cada proveedor
 * (enlace público, Microsoft Graph, Google Drive, Dropbox) lo implementa.
 */
interface ProveedorNube
{
    /**
     * Archivos que hoy tiene el origen en la nube (un solo elemento para un
     * origen de tipo archivo).
     *
     * @return list<ArchivoRemoto>
     *
     * @throws NubeSyncException
     */
    public function listar(SeccionOrigen $origen): array;

    /**
     * Descarga el archivo a `$destino` (ruta local). No valida el contenido:
     * eso lo hace el motor con las mismas reglas que una subida manual.
     *
     * @throws NubeSyncException
     */
    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void;
}
