<?php

namespace App\Services\Nube\Proveedores;

use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Nube\DescargadorNube;
use App\Services\Secciones\EnlaceNubeResolver;

/**
 * Enlace "Compartir" público de Google Drive, OneDrive/SharePoint o Dropbox,
 * sin cuenta conectada. Solo archivos sueltos: el enlace no permite listar.
 */
final class EnlacePublico implements ProveedorNube
{
    public const ID_ARCHIVO = 'archivo';

    public function __construct(
        private readonly DescargadorNube $descargador,
        private readonly EnlaceNubeResolver $resolver,
    ) {}

    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto
    {
        // Valida el host y que sea un enlace de archivo (lanza ValidationException si no).
        $this->resolver->resolver($enlace);

        $nombre = basename((string) parse_url($enlace, PHP_URL_PATH));

        return new OrigenRemoto(OrigenRemoto::ARCHIVO, null, self::ID_ARCHIVO, $nombre !== '' ? $nombre : self::ID_ARCHIVO);
    }

    public function listar(SeccionOrigen $origen): array
    {
        $nombre = (string) $origen->nombre;

        return [new ArchivoRemoto(self::ID_ARCHIVO, $nombre, $nombre)];
    }

    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto
    {
        return new ListadoRemoto($this->listar($origen));
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $this->descargador->descargar((string) $origen->enlace_original, $destino);
    }
}
