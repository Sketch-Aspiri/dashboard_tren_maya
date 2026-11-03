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
 * OneDrive personal/institucional y SharePoint por Microsoft Graph. El
 * enlace se resuelve con /shares y el token de la cuenta (lo que el enlace
 * anónimo no permite en cuentas institucionales). El token de versión es el
 * cTag, que solo cambia cuando cambia el contenido.
 */
final class MicrosoftGraph extends ProveedorConCuenta
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    private const CAMPOS = 'id,name,size,cTag,eTag,file,folder,lastModifiedDateTime,parentReference,remoteItem';

    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto
    {
        $cuenta = $this->exigirCuenta($cuenta);
        $codificado = 'u!'.rtrim(strtr(base64_encode(trim($enlace)), '+/', '-_'), '=');

        $item = $this->cliente->json($cuenta, 'GET', self::BASE."/shares/{$codificado}/driveItem", [
            'query' => ['$select' => self::CAMPOS],
        ]);

        [$driveId, $itemId, $real] = $this->identidad($item, null);
        $nombre = (string) ($item['name'] ?? $itemId);
        $acumulador = new AcumuladorListado;

        if (isset($real['folder'])) {
            $this->recorrer($cuenta, $driveId, $itemId, true, $acumulador);
            $listado = $acumulador->resultado();

            return new OrigenRemoto(OrigenRemoto::CARPETA, $driveId, $itemId, $nombre, count($listado->archivos), $listado->omitidos, $listado->excel());
        }

        $acumulador->agregar($this->archivoDe($item, $nombre, $driveId));
        $listado = $acumulador->resultado();

        return new OrigenRemoto(OrigenRemoto::ARCHIVO, $driveId, $itemId, $nombre, count($listado->archivos), $listado->omitidos, $listado->excel());
    }

    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto
    {
        $cuenta = $this->cuentaDelOrigen($origen);
        $driveId = (string) $origen->remoto_drive_id;
        $itemId = (string) $origen->remoto_item_id;
        $acumulador = new AcumuladorListado;

        if ($origen->tipo === SeccionOrigen::TIPO_CARPETA) {
            $this->recorrer($cuenta, $driveId, $itemId, $this->incluyeSubcarpetas($origen), $acumulador);

            return $acumulador->resultado();
        }

        $item = $this->cliente->json($cuenta, 'GET', $this->urlItem($driveId, $itemId), [
            'query' => ['$select' => self::CAMPOS],
        ]);
        $acumulador->agregar($this->archivoDe($item, (string) ($item['name'] ?? $origen->nombre), $driveId));

        return $acumulador->resultado();
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $driveId = $archivo->driveId ?? (string) $origen->remoto_drive_id;

        // /content responde 302 a una URL prefirmada de otro host: ClienteNube
        // la sigue a mano, validándola y sin reenviar el Bearer.
        $this->cliente->descargar($this->cuentaDelOrigen($origen), 'GET', $this->urlItem($driveId, $archivo->id).'/content', $destino);
    }

    private function recorrer(NubeCuenta $cuenta, string $driveId, string $itemId, bool $incluirSub, AcumuladorListado $acumulador, string $ruta = '', int $profundidad = 1): void
    {
        $url = $this->urlItem($driveId, $itemId).'/children';
        $opciones = ['query' => ['$select' => self::CAMPOS, '$top' => 200]];

        while ($url !== null) {
            $pagina = $this->cliente->json($cuenta, 'GET', $url, $opciones);

            foreach ($pagina['value'] ?? [] as $item) {
                if ($acumulador->lleno()) {
                    $acumulador->omitir(MotivoOmision::TopeArchivos);

                    return;
                }

                $this->procesarItem($cuenta, $item, $driveId, $incluirSub, $acumulador, $ruta, $profundidad);
            }

            // nextLink ya trae su propia query completa.
            $url = is_string($pagina['@odata.nextLink'] ?? null) ? $pagina['@odata.nextLink'] : null;
            $opciones = [];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function procesarItem(NubeCuenta $cuenta, array $item, string $driveId, bool $incluirSub, AcumuladorListado $acumulador, string $ruta, int $profundidad): void
    {
        [$itemDrive, $itemId, $real] = $this->identidad($item, $driveId);
        $nombre = (string) ($item['name'] ?? $itemId);

        if (isset($real['folder'])) {
            if (! $incluirSub) {
                return;
            }

            if ($profundidad >= $this->maxProfundidad()) {
                $acumulador->omitir(MotivoOmision::Profundidad);

                return;
            }

            $this->recorrer($cuenta, $itemDrive, $itemId, true, $acumulador, $this->unirRuta($ruta, $nombre), $profundidad + 1);

            return;
        }

        if (! isset($real['file'])) {
            $acumulador->omitir(MotivoOmision::ExtensionNoPermitida);

            return;
        }

        $acumulador->agregar($this->archivoDe($item, $this->unirRuta($ruta, $nombre), $itemDrive));
    }

    /**
     * Un acceso directo a un elemento compartido ("remoteItem") apunta a otro
     * drive: se usan sus identificadores.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: string, 2: array<string, mixed>} driveId, itemId, elemento real
     */
    private function identidad(array $item, ?string $driveHeredado): array
    {
        $real = is_array($item['remoteItem'] ?? null) ? $item['remoteItem'] : $item;
        $driveId = $real['parentReference']['driveId'] ?? $item['parentReference']['driveId'] ?? $driveHeredado;
        $itemId = $real['id'] ?? $item['id'] ?? null;

        if (! is_string($driveId) || ! is_string($itemId)) {
            throw new NubeSyncException(__('La nube devolvió un elemento sin identificador.'));
        }

        return [$driveId, $itemId, $real];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function archivoDe(array $item, string $ruta, string $driveId): ArchivoRemoto
    {
        [$itemDrive, $itemId, $real] = $this->identidad($item, $driveId);

        return new ArchivoRemoto(
            id: $itemId,
            nombre: (string) ($item['name'] ?? $itemId),
            rutaRelativa: $ruta,
            tamano: isset($real['size']) ? (int) $real['size'] : null,
            version: $real['cTag'] ?? $item['cTag'] ?? $real['eTag'] ?? $item['eTag'] ?? null,
            driveId: $itemDrive,
            mime: $real['file']['mimeType'] ?? null,
            modificadoEn: $this->fecha($real['lastModifiedDateTime'] ?? $item['lastModifiedDateTime'] ?? null),
        );
    }

    private function urlItem(string $driveId, string $itemId): string
    {
        return self::BASE.'/drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId);
    }
}
