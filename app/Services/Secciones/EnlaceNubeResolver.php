<?php

namespace App\Services\Secciones;

use Illuminate\Validation\ValidationException;

/**
 * Convierte el enlace "para compartir" que pega el usuario en la URL de
 * descarga directa del proveedor. Solo acepta https y dominios de
 * config('secciones.nube.hosts_permitidos'): el servidor nunca descarga de
 * un host arbitrario (SSRF).
 */
final class EnlaceNubeResolver
{
    public const GOOGLE = 'google';

    public const MICROSOFT = 'microsoft';

    public const DROPBOX = 'dropbox';

    private const ID_GOOGLE = '[A-Za-z0-9_-]{10,}';

    /**
     * Documentos de Google Workspace: se exportan al formato de Office.
     *
     * @var array<string, string>
     */
    private const EXPORTACION_GOOGLE = [
        'spreadsheets' => 'xlsx',
        'document' => 'docx',
        'presentation' => 'pptx',
    ];

    /**
     * @return array{proveedor: string, descarga: string}
     */
    public function resolver(string $enlace): array
    {
        $partes = parse_url(trim($enlace));

        if (! is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || ! isset($partes['host'])
            || isset($partes['user']) || isset($partes['pass']) || ! in_array($partes['port'] ?? 443, [443], true)) {
            throw $this->rechazo(__('El enlace debe ser una dirección https de Google Drive, OneDrive, SharePoint o Dropbox.'));
        }

        $host = strtolower($partes['host']);

        if (! $this->hostPermitido($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw $this->rechazo(__('Solo se aceptan enlaces de Google Drive, OneDrive, SharePoint o Dropbox.'));
        }

        return match (true) {
            $this->esHost($host, ['drive.google.com', 'docs.google.com']) => $this->google($host, $partes),
            $this->esHost($host, ['1drv.ms', 'onedrive.live.com']) => $this->onedrive(trim($enlace)),
            $this->esHost($host, ['sharepoint.com']) => $this->sharepoint($partes),
            $this->esHost($host, ['dropbox.com']) => $this->dropbox($partes),
            default => throw $this->rechazo(__('Este enlace no es de un archivo compartido. Copia el enlace de "Compartir" del archivo.')),
        };
    }

    /**
     * El host está en la lista de dominios permitidos (o es subdominio de uno).
     * Lo usa también la descarga para validar cada redirección.
     */
    public function hostPermitido(string $host): bool
    {
        return $this->esHost(strtolower($host), config('secciones.nube.hosts_permitidos'));
    }

    /**
     * @param  array<string, mixed>  $partes
     * @return array{proveedor: string, descarga: string}
     */
    private function google(string $host, array $partes): array
    {
        $ruta = $partes['path'] ?? '';
        parse_str($partes['query'] ?? '', $consulta);

        if ($host === 'docs.google.com' && preg_match('#^/(spreadsheets|document|presentation)/d/('.self::ID_GOOGLE.')#', $ruta, $m)) {
            return ['proveedor' => self::GOOGLE, 'descarga' => "https://docs.google.com/{$m[1]}/d/{$m[2]}/export?format=".self::EXPORTACION_GOOGLE[$m[1]]];
        }

        $id = preg_match('#^/file/d/('.self::ID_GOOGLE.')#', $ruta, $m) ? $m[1] : ($consulta['id'] ?? null);

        if ($host === 'drive.google.com' && is_string($id) && preg_match('/^'.self::ID_GOOGLE.'$/', $id)) {
            return ['proveedor' => self::GOOGLE, 'descarga' => "https://drive.google.com/uc?export=download&id={$id}"];
        }

        throw $this->rechazo(__('No se encontró un archivo en ese enlace de Google. Abre el archivo y copia el enlace de "Compartir".'));
    }

    /**
     * OneDrive personal: la API de "shares" acepta el enlace en base64 url-safe.
     *
     * @return array{proveedor: string, descarga: string}
     */
    private function onedrive(string $enlace): array
    {
        $codificado = rtrim(strtr(base64_encode($enlace), '+/', '-_'), '=');

        return ['proveedor' => self::MICROSOFT, 'descarga' => "https://api.onedrive.com/v1.0/shares/u!{$codificado}/root/content"];
    }

    /**
     * @param  array<string, mixed>  $partes
     * @return array{proveedor: string, descarga: string}
     */
    private function sharepoint(array $partes): array
    {
        parse_str($partes['query'] ?? '', $consulta);
        $consulta['download'] = '1';

        return [
            'proveedor' => self::MICROSOFT,
            'descarga' => "https://{$partes['host']}".($partes['path'] ?? '/').'?'.http_build_query($consulta),
        ];
    }

    /**
     * @param  array<string, mixed>  $partes
     * @return array{proveedor: string, descarga: string}
     */
    private function dropbox(array $partes): array
    {
        if (! isset($partes['path']) || $partes['path'] === '/') {
            throw $this->rechazo(__('Este enlace de Dropbox no apunta a un archivo.'));
        }

        parse_str($partes['query'] ?? '', $consulta);
        $consulta['dl'] = '1';

        return [
            'proveedor' => self::DROPBOX,
            'descarga' => "https://{$partes['host']}{$partes['path']}?".http_build_query($consulta),
        ];
    }

    /**
     * @param  list<string>  $dominios
     */
    private function esHost(string $host, array $dominios): bool
    {
        foreach ($dominios as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    private function rechazo(string $mensaje): ValidationException
    {
        return ValidationException::withMessages(['enlace' => $mensaje]);
    }
}
