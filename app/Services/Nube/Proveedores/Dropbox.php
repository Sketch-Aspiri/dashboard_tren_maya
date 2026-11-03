<?php

namespace App\Services\Nube\Proveedores;

use App\Enums\MotivoOmision;
use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\AcumuladorListado;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Secciones\NubeSyncException;

/**
 * Dropbox con un enlace compartido (el `enlace_original` del origen):
 * metadatos con get_shared_link_metadata, listado recursivo con
 * list_folder(shared_link) + continue, y descarga con get_shared_link_file.
 * El token de versión es content_hash (o rev en un archivo suelto).
 */
final class Dropbox extends ProveedorConCuenta
{
    private const API = 'https://api.dropboxapi.com/2';

    private const CONTENIDO = 'https://content.dropboxapi.com/2';

    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto
    {
        $cuenta = $this->exigirCuenta($cuenta);
        $enlace = trim($enlace);

        $meta = $this->metadatosEnlace($cuenta, $enlace);
        $id = (string) ($meta['id'] ?? $enlace);
        $nombre = (string) ($meta['name'] ?? $id);
        $acumulador = new AcumuladorListado;

        if (($meta['.tag'] ?? null) === 'folder') {
            $this->recorrer($cuenta, $enlace, true, $acumulador);
            $listado = $acumulador->resultado();

            return new OrigenRemoto(OrigenRemoto::CARPETA, null, $id, $nombre, count($listado->archivos), $listado->omitidos, $listado->excel());
        }

        $acumulador->agregar($this->archivoSuelto($meta, $nombre));
        $listado = $acumulador->resultado();

        return new OrigenRemoto(OrigenRemoto::ARCHIVO, null, $id, $nombre, count($listado->archivos), $listado->omitidos, $listado->excel());
    }

    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto
    {
        $cuenta = $this->cuentaDelOrigen($origen);
        $enlace = (string) $origen->enlace_original;
        $acumulador = new AcumuladorListado;

        if ($origen->tipo === SeccionOrigen::TIPO_CARPETA) {
            $this->recorrer($cuenta, $enlace, $this->incluyeSubcarpetas($origen), $acumulador);

            return $acumulador->resultado();
        }

        $meta = $this->metadatosEnlace($cuenta, $enlace);
        $acumulador->agregar($this->archivoSuelto($meta, (string) ($meta['name'] ?? $origen->nombre)));

        return $acumulador->resultado();
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $argumentos = ['url' => (string) $origen->enlace_original];

        if ($origen->tipo === SeccionOrigen::TIPO_CARPETA) {
            $argumentos['path'] = '/'.ltrim($archivo->rutaRelativa, '/');
        }

        // json_encode escapa los caracteres no ASCII, como exige la cabecera Dropbox-API-Arg.
        $this->cliente->descargar(
            $this->cuentaDelOrigen($origen),
            'POST',
            self::CONTENIDO.'/sharing/get_shared_link_file',
            $destino,
            ['cabeceras' => ['Dropbox-API-Arg' => (string) json_encode($argumentos)]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadatosEnlace(NubeCuenta $cuenta, string $enlace): array
    {
        return $this->cliente->json($cuenta, 'POST', self::API.'/sharing/get_shared_link_metadata', ['json' => ['url' => $enlace]]);
    }

    private function recorrer(NubeCuenta $cuenta, string $enlace, bool $incluirSub, AcumuladorListado $acumulador): void
    {
        $respuesta = $this->cliente->json($cuenta, 'POST', self::API.'/files/list_folder', ['json' => [
            'path' => '',
            'shared_link' => ['url' => $enlace],
            'recursive' => $incluirSub,
            'limit' => 2000,
        ]]);

        while (true) {
            foreach ($respuesta['entries'] ?? [] as $entrada) {
                if ($acumulador->lleno()) {
                    $acumulador->omitir(MotivoOmision::TopeArchivos);

                    return;
                }

                $this->procesar($entrada, $acumulador);
            }

            $cursor = $respuesta['cursor'] ?? null;

            if (($respuesta['has_more'] ?? false) !== true || ! is_string($cursor)) {
                return;
            }

            $respuesta = $this->cliente->json($cuenta, 'POST', self::API.'/files/list_folder/continue', ['json' => ['cursor' => $cursor]]);
        }
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    private function procesar(array $entrada, AcumuladorListado $acumulador): void
    {
        if (($entrada['.tag'] ?? null) !== 'file') {
            return;
        }

        $ruta = ltrim((string) ($entrada['path_display'] ?? $entrada['path_lower'] ?? $entrada['name'] ?? ''), '/');

        // En una lista recursiva no hay "carpeta actual": la profundidad sale de la ruta.
        if (substr_count($ruta, '/') >= $this->maxProfundidad()) {
            $acumulador->omitir(MotivoOmision::Profundidad);

            return;
        }

        $acumulador->agregar(new ArchivoRemoto(
            id: (string) ($entrada['id'] ?? $ruta),
            nombre: (string) ($entrada['name'] ?? basename($ruta)),
            rutaRelativa: $ruta,
            tamano: isset($entrada['size']) ? (int) $entrada['size'] : null,
            version: $entrada['content_hash'] ?? $entrada['rev'] ?? null,
            modificadoEn: $this->fecha($entrada['server_modified'] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function archivoSuelto(array $meta, string $nombre): ArchivoRemoto
    {
        if (($meta['.tag'] ?? 'file') !== 'file') {
            throw new NubeSyncException(__('El enlace de Dropbox no es un archivo ni una carpeta.'));
        }

        return new ArchivoRemoto(
            id: (string) ($meta['id'] ?? $nombre),
            nombre: $nombre,
            rutaRelativa: $nombre,
            tamano: isset($meta['size']) ? (int) $meta['size'] : null,
            version: $meta['content_hash'] ?? $meta['rev'] ?? null,
            modificadoEn: $this->fecha($meta['server_modified'] ?? null),
        );
    }
}
