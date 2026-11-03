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
 * Google Drive (API v3). Los archivos normales se detectan por md5Checksum;
 * los documentos nativos (Docs, Sheets, Slides) no tienen md5, se exportan a
 * docx/xlsx/pptx y su token es el `version` de Drive.
 */
final class GoogleDrive extends ProveedorConCuenta
{
    private const BASE = 'https://www.googleapis.com/drive/v3/files';

    private const CAMPOS = 'id,name,mimeType,size,md5Checksum,modifiedTime,version,shortcutDetails';

    private const CARPETA = 'application/vnd.google-apps.folder';

    private const ACCESO_DIRECTO = 'application/vnd.google-apps.shortcut';

    private const PREFIJO_NATIVO = 'application/vnd.google-apps.';

    private const ID = '[A-Za-z0-9_-]{10,200}';

    /** Documentos nativos => [extensión, tipo MIME de exportación]. */
    private const EXPORTACION = [
        'application/vnd.google-apps.document' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'application/vnd.google-apps.spreadsheet' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'application/vnd.google-apps.presentation' => ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto
    {
        $cuenta = $this->exigirCuenta($cuenta);
        $id = $this->idDeEnlace($enlace);

        $archivo = $this->cliente->json($cuenta, 'GET', self::BASE.'/'.$id, [
            'query' => ['fields' => self::CAMPOS, 'supportsAllDrives' => 'true'],
        ]);
        $nombre = (string) ($archivo['name'] ?? $id);
        $acumulador = new AcumuladorListado;

        if (($archivo['mimeType'] ?? null) === self::CARPETA) {
            $this->recorrer($cuenta, $id, true, $acumulador);
            $listado = $acumulador->resultado();

            return new OrigenRemoto(OrigenRemoto::CARPETA, null, $id, $nombre, count($listado->archivos), $listado->omitidos);
        }

        $this->procesar($archivo, '', $acumulador);
        $listado = $acumulador->resultado();

        return new OrigenRemoto(OrigenRemoto::ARCHIVO, null, $id, $nombre, count($listado->archivos), $listado->omitidos);
    }

    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto
    {
        $cuenta = $this->cuentaDelOrigen($origen);
        $id = $this->validarId((string) $origen->remoto_item_id);
        $acumulador = new AcumuladorListado;

        if ($origen->tipo === SeccionOrigen::TIPO_CARPETA) {
            $this->recorrer($cuenta, $id, $this->incluyeSubcarpetas($origen), $acumulador);

            return $acumulador->resultado();
        }

        $archivo = $this->cliente->json($cuenta, 'GET', self::BASE.'/'.$id, [
            'query' => ['fields' => self::CAMPOS, 'supportsAllDrives' => 'true'],
        ]);
        $this->procesar($archivo, '', $acumulador);

        return $acumulador->resultado();
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $cuenta = $this->cuentaDelOrigen($origen);
        $id = $this->validarId($archivo->id);
        $exportacion = self::EXPORTACION[$archivo->mime ?? ''] ?? null;

        $this->cliente->descargar(
            $cuenta,
            'GET',
            self::BASE.'/'.$id.($exportacion !== null ? '/export' : ''),
            $destino,
            ['query' => $exportacion !== null
                ? ['mimeType' => $exportacion[1]]
                : ['alt' => 'media', 'supportsAllDrives' => 'true']],
        );
    }

    private function recorrer(NubeCuenta $cuenta, string $carpetaId, bool $incluirSub, AcumuladorListado $acumulador, string $ruta = '', int $profundidad = 1): void
    {
        $pagina = null;

        do {
            $consulta = [
                'q' => "'{$this->validarId($carpetaId)}' in parents and trashed=false",
                'fields' => 'nextPageToken,files('.self::CAMPOS.')',
                'pageSize' => 1000,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ] + ($pagina !== null ? ['pageToken' => $pagina] : []);

            $respuesta = $this->cliente->json($cuenta, 'GET', self::BASE, ['query' => $consulta]);

            foreach ($respuesta['files'] ?? [] as $archivo) {
                if ($acumulador->lleno()) {
                    $acumulador->omitir(MotivoOmision::TopeArchivos);

                    return;
                }

                if (($archivo['mimeType'] ?? null) === self::CARPETA) {
                    $this->entrarEnCarpeta($cuenta, $archivo, $incluirSub, $acumulador, $ruta, $profundidad);

                    continue;
                }

                $this->procesar($archivo, $ruta, $acumulador);
            }

            $pagina = is_string($respuesta['nextPageToken'] ?? null) ? $respuesta['nextPageToken'] : null;
        } while ($pagina !== null);
    }

    /**
     * @param  array<string, mixed>  $carpeta
     */
    private function entrarEnCarpeta(NubeCuenta $cuenta, array $carpeta, bool $incluirSub, AcumuladorListado $acumulador, string $ruta, int $profundidad): void
    {
        if (! $incluirSub) {
            return;
        }

        if ($profundidad >= $this->maxProfundidad()) {
            $acumulador->omitir(MotivoOmision::Profundidad);

            return;
        }

        $this->recorrer($cuenta, (string) $carpeta['id'], true, $acumulador, $this->unirRuta($ruta, (string) ($carpeta['name'] ?? $carpeta['id'])), $profundidad + 1);
    }

    /**
     * @param  array<string, mixed>  $archivo
     */
    private function procesar(array $archivo, string $ruta, AcumuladorListado $acumulador): void
    {
        $mime = (string) ($archivo['mimeType'] ?? '');
        $nativo = self::EXPORTACION[$mime] ?? null;
        $nombre = (string) ($archivo['name'] ?? $archivo['id']).($nativo !== null ? '.'.$nativo[0] : '');

        if ($mime !== self::ACCESO_DIRECTO && $nativo === null && str_starts_with($mime, self::PREFIJO_NATIVO)) {
            $acumulador->omitir(MotivoOmision::ExtensionNoPermitida);

            return;
        }

        $remoto = new ArchivoRemoto(
            id: (string) $archivo['id'],
            nombre: $nombre,
            rutaRelativa: $this->unirRuta($ruta, $nombre),
            tamano: isset($archivo['size']) ? (int) $archivo['size'] : null,
            version: $archivo['md5Checksum'] ?? (isset($archivo['version']) ? 'v'.$archivo['version'] : null),
            mime: $mime,
            modificadoEn: $this->fecha($archivo['modifiedTime'] ?? null),
        );

        $acumulador->agregar($remoto, $mime === self::ACCESO_DIRECTO);
    }

    private function idDeEnlace(string $enlace): string
    {
        $partes = parse_url(trim($enlace));
        $host = strtolower((string) ($partes['host'] ?? ''));

        if (! in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
            throw new NubeSyncException(__('Solo se aceptan enlaces de Google Drive.'));
        }

        parse_str($partes['query'] ?? '', $consulta);

        $id = preg_match('#/(?:folders|d)/('.self::ID.')#', (string) ($partes['path'] ?? ''), $m) === 1
            ? $m[1]
            : ($consulta['id'] ?? null);

        if (! is_string($id)) {
            throw new NubeSyncException(__('No se encontró un archivo o carpeta en ese enlace de Google Drive.'));
        }

        return $this->validarId($id);
    }

    /**
     * El id entra en la consulta `q` de Drive: solo el alfabeto de los ids de
     * Google, nada que pueda alterar la consulta.
     */
    private function validarId(string $id): string
    {
        if (preg_match('/^'.self::ID.'$/', $id) !== 1) {
            throw new NubeSyncException(__('El identificador de Google Drive no es válido.'));
        }

        return $id;
    }
}
