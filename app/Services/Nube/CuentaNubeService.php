<?php

namespace App\Services\Nube;

use App\Enums\CodigoErrorNube;
use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Models\User;
use App\Services\Nube\Datos\TokensOAuth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de las cuentas de nube: conectar (retorno del OAuth),
 * mantener el token vigente, refrescar (marcando "requiere reconexión" si el
 * proveedor lo rechaza) y desconectar (revocación local). Cada cambio queda
 * en la bitácora sin tokens.
 */
final class CuentaNubeService
{
    /** Segundos de margen: se renueva el token si vence en menos que esto. */
    private const MARGEN_EXPIRACION = 60;

    /** Segundos que dura el bloqueo del refresh de una cuenta, y lo que se espera por él. */
    private const BLOQUEO_REFRESH = 30;

    private const ESPERA_BLOQUEO = 10;

    public function __construct(
        private readonly OAuthNubeService $oauth,
        private readonly CredencialesNubeService $credenciales,
    ) {}

    /**
     * @param  array<string, mixed>  $consulta  query del retorno del proveedor
     *
     * @throws NubeOAuthException
     */
    public function conectarDesdeCallback(ProveedorNube $proveedor, array $consulta, User $actor): NubeCuenta
    {
        $resultado = $this->oauth->intercambiar($proveedor, $consulta);
        $tokens = $resultado['tokens'];

        $cuenta = DB::transaction(function () use ($proveedor, $resultado, $tokens, $actor): NubeCuenta {
            // withTrashed: reconectar una cuenta dada de baja la restaura (unique proveedor+correo).
            $cuenta = NubeCuenta::withTrashed()->firstOrNew([
                'proveedor' => $proveedor->value,
                'cuenta_email' => $resultado['email'],
            ]);

            $cuenta->deleted_at = null;
            $cuenta->fill([
                'cuenta_nombre' => $resultado['nombre'],
                'tenant_id' => $proveedor->usaTenant() ? $this->credenciales->resolver($proveedor)?->tenant : null,
                'estado' => EstadoCuentaNube::Activa,
                'ultimo_error' => null,
                'conectada_por' => $actor->id,
            ]);
            $this->aplicarTokens($cuenta, $tokens);
            $cuenta->save();

            return $cuenta;
        });

        $this->registrar($actor, $cuenta, 'cuenta_nube_conectada', 'Cuenta de nube conectada.');

        return $cuenta;
    }

    /**
     * Token de acceso listo para usar; lo renueva si está por vencer.
     *
     * @throws NubeOAuthException
     */
    public function tokenVigente(NubeCuenta $cuenta): string
    {
        if (! $cuenta->estaActiva()) {
            throw new NubeOAuthException(__('La cuenta de nube necesita reconectarse.'), codigo: CodigoErrorNube::Reconexion);
        }

        $vencido = $cuenta->expira_en !== null && $cuenta->expira_en->subSeconds(self::MARGEN_EXPIRACION)->isPast();

        if ($vencido || (string) $cuenta->access_token === '') {
            $this->refrescar($cuenta);
        }

        return (string) $cuenta->access_token;
    }

    /**
     * Pide un token nuevo, una cuenta a la vez (Cache::lock): si otro proceso
     * ya refrescó mientras se esperaba, se releen sus tokens. La cuenta solo
     * pasa a "requiere reconexión" ante un rechazo definitivo del proveedor;
     * timeouts, 429 y 5xx lanzan la excepción sin cambiar el estado.
     *
     * @throws NubeOAuthException
     */
    public function refrescar(NubeCuenta $cuenta): void
    {
        $tokenAnterior = (string) $cuenta->access_token;
        $bloqueo = Cache::lock('nube-refresh-'.$cuenta->id, self::BLOQUEO_REFRESH);

        try {
            $bloqueo->block(self::ESPERA_BLOQUEO);
        } catch (LockTimeoutException) {
            throw new NubeOAuthException(__('Otra renovación de la cuenta sigue en curso. Se reintentará en la próxima revisión.'), codigo: CodigoErrorNube::Transitorio);
        }

        try {
            $this->refrescarConBloqueo($cuenta, $tokenAnterior);
        } finally {
            $bloqueo->release();
        }
    }

    private function refrescarConBloqueo(NubeCuenta $cuenta, string $tokenAnterior): void
    {
        $cuenta->refresh();

        if (! $cuenta->estaActiva()) {
            throw new NubeOAuthException(__('La cuenta de nube necesita reconectarse.'), codigo: CodigoErrorNube::Reconexion);
        }

        if ((string) $cuenta->access_token !== $tokenAnterior && (string) $cuenta->access_token !== '') {
            return; // otro proceso ya renovó
        }

        try {
            $tokens = $this->oauth->refrescar($cuenta);
        } catch (NubeOAuthException $e) {
            if ($e->codigo === CodigoErrorNube::Reconexion) {
                $cuenta->forceFill([
                    'estado' => EstadoCuentaNube::RequiereReconexion,
                    'ultimo_error' => $e->getMessage(),
                ])->save();
            }

            throw $e;
        }

        $this->aplicarTokens($cuenta, $tokens);
        $cuenta->forceFill(['estado' => EstadoCuentaNube::Activa, 'ultimo_error' => null])->save();
    }

    /**
     * Revoca la cuenta localmente: borra los tokens, la da de baja y pausa los
     * orígenes que la usaban. (El permiso en el proveedor se quita desde la
     * cuenta de ese proveedor; ver docs/nube-oauth.md.)
     */
    public function desconectar(NubeCuenta $cuenta, User $actor): void
    {
        DB::transaction(function () use ($cuenta): void {
            $cuenta->origenes()->whereIn('estado', [EstadoOrigen::Activo->value, EstadoOrigen::PendienteMapeo->value])->update([
                'estado' => EstadoOrigen::Pausado->value,
                'sincronizacion_error' => __('La cuenta de nube se desconectó.'),
            ]);

            $cuenta->forceFill([
                'access_token' => null,
                'refresh_token' => null,
                'expira_en' => null,
            ])->save();
            $cuenta->delete();
        });

        $this->registrar($actor, $cuenta, 'cuenta_nube_desconectada', 'Cuenta de nube desconectada.');
    }

    private function aplicarTokens(NubeCuenta $cuenta, TokensOAuth $tokens): void
    {
        $cuenta->access_token = $tokens->accessToken;
        // Google/Microsoft pueden omitir el refresh token al renovar: se conserva el actual.
        if ($tokens->refreshToken !== null) {
            $cuenta->refresh_token = $tokens->refreshToken;
        }
        $cuenta->expira_en = $tokens->expiraEn;
        $cuenta->scopes = $tokens->scopes;
    }

    private function registrar(User $actor, NubeCuenta $cuenta, string $evento, string $mensaje): void
    {
        activity('nube')
            ->causedBy($actor)
            ->performedOn($cuenta)
            ->event($evento)
            ->withProperties(['proveedor' => $cuenta->proveedor->value, 'cuenta' => $cuenta->cuenta_email])
            ->log($mensaje);
    }
}
