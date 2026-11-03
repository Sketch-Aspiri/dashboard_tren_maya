<?php

namespace App\Services\Nube;

use App\Enums\ProveedorNube;
use App\Models\NubeCredencial;
use App\Models\User;
use App\Services\Nube\Datos\CredencialesOAuth;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

/**
 * Credenciales de las apps OAuth. Primero la fila de la base de datos (las
 * carga el Administrador en "Conexiones de nube"); si no hay, el .env
 * (config/services.php). Nunca expone el secreto: la UI solo recibe
 * "configurado / falta" y los últimos 4 caracteres del Client ID.
 */
final class CredencialesNubeService
{
    public const ORIGEN_APP = 'app';

    public const ORIGEN_ENV = 'env';

    /**
     * Siempre calculada, nunca tomada del .env: es lo que TI registra en el
     * proveedor y lo que la UI muestra.
     */
    public function redirectUri(ProveedorNube $proveedor): string
    {
        return route('nube.callback', ['proveedor' => $proveedor->value]);
    }

    public function resolver(ProveedorNube $proveedor): ?CredencialesOAuth
    {
        return $this->desdeBaseDeDatos($proveedor) ?? $this->desdeEntorno($proveedor);
    }

    /**
     * Estado apto para la vista: sin secretos.
     *
     * @return array{configurado: bool, origen: ?string, client_id_fin: ?string, tenant: ?string}
     */
    public function estado(ProveedorNube $proveedor): array
    {
        $credenciales = $this->resolver($proveedor);

        return [
            'configurado' => $credenciales !== null,
            'origen' => $credenciales?->origen,
            'client_id_fin' => $credenciales !== null ? substr($credenciales->clientId, -4) : null,
            'tenant' => $proveedor->usaTenant() ? ($credenciales?->tenant ?? ProveedorNube::TENANT_POR_DEFECTO) : null,
        ];
    }

    /**
     * ¿Hay ya una credencial guardada en la base de datos? (Client ID y
     * secreto solo son obligatorios la primera vez; en blanco = conservar).
     */
    public function tieneCredencialGuardada(ProveedorNube $proveedor): bool
    {
        return NubeCredencial::query()->where('proveedor', $proveedor->value)->exists();
    }

    /**
     * @param  array{client_id?: ?string, client_secret?: ?string, tenant?: ?string}  $datos
     */
    public function guardar(ProveedorNube $proveedor, array $datos, User $actor): NubeCredencial
    {
        $credencial = NubeCredencial::query()->firstOrNew(['proveedor' => $proveedor->value]);
        $secretoNuevo = trim((string) ($datos['client_secret'] ?? ''));
        $clientIdNuevo = trim((string) ($datos['client_id'] ?? ''));

        $credencial->fill([
            'tenant' => $proveedor->usaTenant() ? (trim((string) ($datos['tenant'] ?? '')) ?: null) : null,
            'actualizado_por' => $actor->id,
        ]);

        if ($clientIdNuevo !== '') {
            $credencial->client_id = $clientIdNuevo;
        }

        if ($secretoNuevo !== '') {
            $credencial->client_secret = $secretoNuevo;
        }

        $credencial->save();

        // La bitácora NUNCA lleva el secreto: solo si cambió.
        activity('nube')
            ->causedBy($actor)
            ->performedOn($credencial)
            ->event('credenciales_nube_actualizadas')
            ->withProperties([
                'proveedor' => $proveedor->value,
                'client_id_fin' => substr($credencial->client_id, -4),
                'secreto_cambiado' => $secretoNuevo !== '',
                'tenant' => $credencial->tenant,
            ])
            ->log('Credenciales de nube actualizadas.');

        return $credencial;
    }

    private function desdeBaseDeDatos(ProveedorNube $proveedor): ?CredencialesOAuth
    {
        $credencial = NubeCredencial::query()->where('proveedor', $proveedor->value)->first();

        if ($credencial === null) {
            return null;
        }

        try {
            $secreto = (string) $credencial->client_secret;
        } catch (DecryptException) {
            // APP_KEY distinta a la que cifró el secreto: se trata como no configurado.
            return null;
        }

        if ($credencial->client_id === '' || $secreto === '') {
            return null;
        }

        return new CredencialesOAuth(
            $credencial->client_id,
            $secreto,
            $this->tenantDe($proveedor, $credencial->tenant),
            self::ORIGEN_APP,
        );
    }

    private function desdeEntorno(ProveedorNube $proveedor): ?CredencialesOAuth
    {
        $clientId = trim((string) config("services.{$proveedor->value}.client_id"));
        $secreto = trim((string) config("services.{$proveedor->value}.client_secret"));

        if ($clientId === '' || $secreto === '') {
            return null;
        }

        return new CredencialesOAuth(
            $clientId,
            $secreto,
            $this->tenantDe($proveedor, config("services.{$proveedor->value}.tenant")),
            self::ORIGEN_ENV,
        );
    }

    private function tenantDe(ProveedorNube $proveedor, mixed $tenant): string
    {
        $tenant = Str::of((string) $tenant)->trim()->toString();

        return $proveedor->usaTenant() && $tenant !== '' ? $tenant : ProveedorNube::TENANT_POR_DEFECTO;
    }
}
