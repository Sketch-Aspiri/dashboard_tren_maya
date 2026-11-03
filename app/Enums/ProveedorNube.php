<?php

namespace App\Enums;

/**
 * Nubes con las que el Administrador puede conectar una cuenta por OAuth.
 * Todos los alcances son de solo lectura: el dashboard nunca modifica ni
 * borra nada en la nube.
 */
enum ProveedorNube: string
{
    case Microsoft = 'microsoft';
    case Google = 'google';
    case Dropbox = 'dropbox';

    public const TENANT_POR_DEFECTO = 'common';

    public function label(): string
    {
        return match ($this) {
            self::Microsoft => 'OneDrive / SharePoint (Microsoft)',
            self::Google => 'Google Drive',
            self::Dropbox => 'Dropbox',
        };
    }

    /**
     * Nombre corto para insignias ("Sincronizada con OneDrive").
     */
    public function etiquetaCorta(): string
    {
        return match ($this) {
            self::Microsoft => 'OneDrive',
            self::Google => 'Google Drive',
            self::Dropbox => 'Dropbox',
        };
    }

    public function usaTenant(): bool
    {
        return $this === self::Microsoft;
    }

    /**
     * Alcances OAuth separados por espacio (solo lectura).
     */
    public function scopes(): string
    {
        return match ($this) {
            self::Microsoft => 'offline_access User.Read Files.Read.All Sites.Read.All',
            self::Google => 'https://www.googleapis.com/auth/drive.readonly openid email profile',
            self::Dropbox => 'files.metadata.read files.content.read sharing.read',
        };
    }

    public function urlAutorizacion(string $tenant = self::TENANT_POR_DEFECTO): string
    {
        return match ($this) {
            self::Microsoft => 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/authorize',
            self::Google => 'https://accounts.google.com/o/oauth2/v2/auth',
            self::Dropbox => 'https://www.dropbox.com/oauth2/authorize',
        };
    }

    public function urlToken(string $tenant = self::TENANT_POR_DEFECTO): string
    {
        return match ($this) {
            self::Microsoft => 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token',
            self::Google => 'https://oauth2.googleapis.com/token',
            self::Dropbox => 'https://api.dropboxapi.com/oauth2/token',
        };
    }

    /**
     * Endpoint que devuelve el correo y nombre de la cuenta conectada.
     */
    public function urlUsuario(): string
    {
        return match ($this) {
            self::Microsoft => 'https://graph.microsoft.com/v1.0/me',
            self::Google => 'https://openidconnect.googleapis.com/v1/userinfo',
            self::Dropbox => 'https://api.dropboxapi.com/2/users/get_current_account',
        };
    }

    /**
     * Parámetros extra que cada proveedor necesita para entregar refresh token.
     *
     * @return array<string, string>
     */
    public function parametrosAutorizacion(): array
    {
        return match ($this) {
            self::Microsoft => ['response_mode' => 'query', 'prompt' => 'select_account'],
            self::Google => ['access_type' => 'offline', 'prompt' => 'consent'],
            self::Dropbox => ['token_access_type' => 'offline'],
        };
    }

    /**
     * Dominios de los enlaces "Compartir" de este proveedor. Un enlace de
     * otro sitio (o una IP) nunca se manda a su API (SSRF).
     *
     * @return list<string>
     */
    public function hostsDeEnlace(): array
    {
        return match ($this) {
            self::Microsoft => ['1drv.ms', '1drv.com', 'onedrive.live.com', 'sharepoint.com'],
            self::Google => ['drive.google.com', 'docs.google.com'],
            self::Dropbox => ['dropbox.com'],
        };
    }
}
