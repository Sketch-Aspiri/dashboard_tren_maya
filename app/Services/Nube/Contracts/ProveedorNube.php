<?php

namespace App\Services\Nube\Contracts;

use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Secciones\NubeSyncException;

/**
 * Lo que el motor de sincronización necesita de una nube. Cada proveedor
 * (enlace público, Microsoft Graph, Google Drive, Dropbox) lo implementa.
 */
interface ProveedorNube
{
    /**
     * Qué es lo que apunta un enlace (archivo o carpeta) y cuánto contiene.
     * Sin cuenta solo se acepta un archivo suelto (enlace público).
     *
     * @throws NubeSyncException
     */
    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto;

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
     * Igual que listar(), con lo omitido y su motivo para el resumen del
     * origen. Recursivo y paginado, con los topes de secciones.nube.
     *
     * @throws NubeSyncException
     */
    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto;

    /**
     * Descarga el archivo a `$destino` (ruta local). No valida el contenido:
     * eso lo hace el motor con las mismas reglas que una subida manual.
     *
     * @throws NubeSyncException
     */
    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void;
}
