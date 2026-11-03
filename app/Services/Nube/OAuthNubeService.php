<?php

namespace App\Services\Nube;

use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Services\Nube\Datos\CredencialesOAuth;
use App\Services\Nube\Datos\TokensOAuth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 "authorization code" con PKCE (S256) hecho a mano con el facade
 * Http (sin Socialite). El `state` es aleatorio, de un solo uso y vive en la
 * sesión. Los mensajes de error nunca incluyen tokens, secretos ni cuerpos
 * de respuesta del proveedor.
 */
final class OAuthNubeService
{
    public const SESION = 'nube_oauth';

    /** Segundos que vale un intento de conexión (state). */
    private const VIGENCIA_STATE = 600;

    public function __construct(private readonly CredencialesNubeService $credenciales) {}

    /**
     * @throws NubeOAuthException si el proveedor no tiene credenciales
     */
    public function urlAutorizacion(ProveedorNube $proveedor): string
    {
        $credenciales = $this->exigirCredenciales($proveedor);
        $state = Str::random(40);
        $verificador = Str::random(64);

        session()->put(self::SESION, [
            'proveedor' => $proveedor->value,
            'state' => $state,
            'verificador' => $verificador,
            'creado' => now()->timestamp,
        ]);

        $parametros = [
            'response_type' => 'code',
            'client_id' => $credenciales->clientId,
            'redirect_uri' => $this->credenciales->redirectUri($proveedor),
            'scope' => $proveedor->scopes(),
            'state' => $state,
            'code_challenge' => $this->desafio($verificador),
            'code_challenge_method' => 'S256',
        ] + $proveedor->parametrosAutorizacion();

        return $proveedor->urlAutorizacion($credenciales->tenant).'?'.http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Valida el retorno del proveedor (state de un solo uso) y devuelve los
     * tokens y el perfil de la cuenta.
     *
     * @param  array<string, mixed>  $consulta  query de la redirección
     * @return array{tokens: TokensOAuth, email: string, nombre: ?string}
     *
     * @throws NubeOAuthException
     */
    public function intercambiar(ProveedorNube $proveedor, array $consulta): array
    {
        $verificador = $this->validarRetorno($proveedor, $consulta);
        $credenciales = $this->exigirCredenciales($proveedor);

        $respuesta = $this->enviarToken($proveedor, $credenciales, [
            'grant_type' => 'authorization_code',
            'code' => (string) $consulta['code'],
            'redirect_uri' => $this->credenciales->redirectUri($proveedor),
            'code_verifier' => $verificador,
        ]);

        $tokens = $this->tokensDe($proveedor, $respuesta, null);
        $perfil = $this->perfil($proveedor, $tokens->accessToken);

        return ['tokens' => $tokens] + $perfil;
    }

    /**
     * @throws NubeOAuthException
     */
    public function refrescar(NubeCuenta $cuenta): TokensOAuth
    {
        $refresh = (string) $cuenta->refresh_token;

        if ($refresh === '') {
            throw new NubeOAuthException(__('La cuenta no tiene permiso de renovación. Vuelve a conectarla.'));
        }

        $credenciales = $this->exigirCredenciales($cuenta->proveedor);

        $respuesta = $this->enviarToken($cuenta->proveedor, $credenciales, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'scope' => $cuenta->proveedor === ProveedorNube::Microsoft ? $cuenta->proveedor->scopes() : null,
        ]);

        return $this->tokensDe($cuenta->proveedor, $respuesta, $refresh);
    }

    /**
     * @param  array<string, mixed>  $consulta
     * @return string el verificador PKCE guardado para este intento
     *
     * @throws NubeOAuthException
     */
    private function validarRetorno(ProveedorNube $proveedor, array $consulta): string
    {
        // pull: el state sirve una sola vez, aunque el retorno sea inválido.
        $pendiente = session()->pull(self::SESION);
        $state = is_string($consulta['state'] ?? null) ? $consulta['state'] : '';

        $valido = is_array($pendiente)
            && ($pendiente['proveedor'] ?? null) === $proveedor->value
            && $state !== ''
            && hash_equals((string) $pendiente['state'], $state)
            && (now()->timestamp - (int) $pendiente['creado']) <= self::VIGENCIA_STATE;

        if (! $valido) {
            throw new NubeOAuthException(__('La autorización expiró o no es válida. Inicia la conexión de nuevo.'));
        }

        $this->rechazarSiHayError($consulta);

        if (! is_string($consulta['code'] ?? null) || $consulta['code'] === '') {
            throw new NubeOAuthException(__('El proveedor no devolvió el código de autorización.'));
        }

        return (string) $pendiente['verificador'];
    }

    /**
     * @param  array<string, mixed>  $consulta
     */
    private function rechazarSiHayError(array $consulta): void
    {
        $error = $consulta['error'] ?? null;

        if (! is_string($error) || $error === '') {
            return;
        }

        if ($error === 'access_denied') {
            throw new NubeOAuthException(__('Se canceló la autorización o la cuenta no tiene permiso. No se conectó ninguna cuenta.'));
        }

        $codigo = preg_match('/^[a-z_]{1,60}$/', $error) === 1 ? $error : 'desconocido';

        throw new NubeOAuthException(__('El proveedor rechazó la autorización (:codigo).', ['codigo' => $codigo]));
    }

    private function exigirCredenciales(ProveedorNube $proveedor): CredencialesOAuth
    {
        return $this->credenciales->resolver($proveedor)
            ?? throw new NubeOAuthException(__('Faltan el Client ID y el Client Secret de :nube.', ['nube' => $proveedor->label()]));
    }

    /**
     * @param  array<string, string|null>  $campos
     *
     * @throws NubeOAuthException
     */
    private function enviarToken(ProveedorNube $proveedor, CredencialesOAuth $credenciales, array $campos): Response
    {
        $campos = array_filter($campos, fn (?string $valor): bool => $valor !== null) + [
            'client_id' => $credenciales->clientId,
            'client_secret' => $credenciales->clientSecret,
        ];

        try {
            $respuesta = $this->http()->asForm()->post($proveedor->urlToken($credenciales->tenant), $campos);
        } catch (ConnectionException) {
            throw new NubeOAuthException(__('No se pudo conectar con :nube.', ['nube' => $proveedor->label()]));
        }

        if ($respuesta->failed()) {
            throw new NubeOAuthException(__('El proveedor rechazó las credenciales o el permiso (:codigo). Revisa el Client ID/Secret o reconecta la cuenta.', ['codigo' => $respuesta->status()]));
        }

        return $respuesta;
    }

    private function tokensDe(ProveedorNube $proveedor, Response $respuesta, ?string $refreshAnterior): TokensOAuth
    {
        $acceso = $respuesta->json('access_token');

        if (! is_string($acceso) || $acceso === '') {
            throw new NubeOAuthException(__('El proveedor no devolvió un token de acceso.'));
        }

        $refresh = $respuesta->json('refresh_token');
        $segundos = (int) $respuesta->json('expires_in');
        $scopes = $respuesta->json('scope');

        return new TokensOAuth(
            $acceso,
            is_string($refresh) && $refresh !== '' ? $refresh : $refreshAnterior,
            $segundos > 0 ? CarbonImmutable::now()->addSeconds($segundos) : null,
            is_string($scopes) && $scopes !== '' ? $scopes : $proveedor->scopes(),
        );
    }

    /**
     * @return array{email: string, nombre: ?string}
     *
     * @throws NubeOAuthException
     */
    private function perfil(ProveedorNube $proveedor, string $accessToken): array
    {
        try {
            $peticion = $this->http()->withToken($accessToken);
            $respuesta = $proveedor === ProveedorNube::Dropbox
                ? $peticion->withBody('null', 'application/json')->post($proveedor->urlUsuario())
                : $peticion->get($proveedor->urlUsuario());
        } catch (ConnectionException) {
            throw new NubeOAuthException(__('No se pudo leer la cuenta conectada.'));
        }

        $datos = $respuesta->successful() ? $respuesta->json() : null;
        $email = is_array($datos) ? $this->emailDe($proveedor, $datos) : null;

        if ($email === null) {
            throw new NubeOAuthException(__('No se pudo identificar la cuenta conectada.'));
        }

        $nombre = match ($proveedor) {
            ProveedorNube::Microsoft => $datos['displayName'] ?? null,
            ProveedorNube::Google => $datos['name'] ?? null,
            ProveedorNube::Dropbox => $datos['name']['display_name'] ?? null,
        };

        return ['email' => $email, 'nombre' => is_string($nombre) ? Str::limit($nombre, 250, '') : null];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function emailDe(ProveedorNube $proveedor, array $datos): ?string
    {
        $email = match ($proveedor) {
            ProveedorNube::Microsoft => $datos['mail'] ?? $datos['userPrincipalName'] ?? null,
            ProveedorNube::Google, ProveedorNube::Dropbox => $datos['email'] ?? null,
        };

        return is_string($email) && $email !== '' ? Str::lower(Str::limit($email, 250, '')) : null;
    }

    private function desafio(string $verificador): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('secciones.nube.timeout_segundos'))
            ->acceptJson()
            ->withOptions(['allow_redirects' => false]);
    }
}
