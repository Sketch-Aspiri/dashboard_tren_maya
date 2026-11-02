<?php

namespace App\Services\Nube\Proveedores;

use App\Models\SeccionOrigen;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\DescargadorNube;

/**
 * Enlace "Compartir" público de Google Drive, OneDrive/SharePoint o Dropbox,
 * sin cuenta conectada. Solo archivos sueltos: el enlace no permite listar.
 */
final class EnlacePublico implements ProveedorNube
{
    public const ID_ARCHIVO = 'archivo';

    public function __construct(private readonly DescargadorNube $descargador) {}

    public function listar(SeccionOrigen $origen): array
    {
        $nombre = (string) $origen->nombre;

        return [new ArchivoRemoto(self::ID_ARCHIVO, $nombre, $nombre)];
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $this->descargador->descargar((string) $origen->enlace_original, $destino);
    }
}
