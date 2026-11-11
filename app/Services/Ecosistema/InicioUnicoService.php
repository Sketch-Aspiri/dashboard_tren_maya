<?php

namespace App\Services\Ecosistema;

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Inicio de sesión único del ecosistema (Dashboard + Tickets) en un mismo
 * dispositivo, sin cookies compartidas entre dominios:
 *
 * 1. Sin sesión, la pantalla de login de un sistema redirige (una vez) al
 *    otro: GET /ecosistema/sso?para=<este sistema>.
 * 2. Si allá hay una sesión completa (con 2FA cuando aplica), responde con un
 *    código firmado de un solo uso (FirmaEcosistema) a /ecosistema/entrar; si
 *    no, regresa con ?sin_sesion=1 y se muestra el login normal.
 * 3. El destino busca la cuenta activa con el mismo correo y abre la sesión;
 *    si el origen verificó el 2FA y la cuenta destino lo tiene configurado,
 *    no se vuelve a pedir.
 *
 * Seguridad:
 * - Cada pregunta lleva un `estado` aleatorio guardado en la sesión de quien
 *   pregunta; el código de respuesta lo trae firmado y solo se acepta en ese
 *   mismo navegador (un código ajeno enviado como enlace no sirve).
 * - El correo no basta: la cuenta debe estar vinculada (`sso_vinculado_at`).
 *   La primera vez se pide la contraseña de aquí una sola vez; al iniciar
 *   sesión con el correo que llegó firmado, la cuenta queda vinculada.
 *
 * Cerrar sesión en uno cierra la del otro (código firmado de "salir").
 */
final class InicioUnicoService
{
    public const SESION_INTENTO = 'ecosistema.sso_intentado';

    public const SESION_OMITIR = 'ecosistema.sso_omitir';

    public const SESION_ESTADO = 'ecosistema.sso_estado';

    public const SESION_VINCULO = 'ecosistema.sso_vinculo';

    public function __construct(private readonly FirmaEcosistema $firma) {}

    public function activo(): bool
    {
        return $this->firma->configurado() && $this->otroSistema() !== null;
    }

    /**
     * El otro sistema del ecosistema (hoy solo hay dos).
     */
    public function otroSistema(): ?string
    {
        return collect(array_keys((array) config('ecosistema.urls')))
            ->reject(fn (string $clave) => $clave === config('ecosistema.sistema'))
            ->first();
    }

    public function url(string $sistema, string $ruta): string
    {
        return config('ecosistema.urls.'.$sistema).$ruta;
    }

    /**
     * ¿Conviene preguntarle al otro sistema antes de mostrar el login? Una vez
     * cada pocos minutos por sesión, y solo si el otro sistema responde (para
     * no dejar a nadie en una página de error si está caído).
     */
    public function debePreguntar(Request $request): bool
    {
        // Solo la visita al login que sigue INMEDIATAMENTE a un cierre de sesión o a una respuesta del otro sistema se
        // salta la pregunta (evita ir y venir); la siguiente vuelve a preguntar.
        $omitir = (bool) $request->session()->pull(self::SESION_OMITIR, false);

        if (! $this->activo() || $request->boolean('local') || $omitir) {
            return false;
        }

        // Si la pregunta anterior NO se completó (el otro sistema falló), no se insiste en los próximos minutos: el
        // login normal siempre queda disponible.
        $ultimo = (int) $request->session()->get(self::SESION_INTENTO, 0);

        if ($ultimo > now()->subMinutes((int) config('ecosistema.sso.reintento_minutos', 5))->getTimestamp()) {
            return false;
        }

        return $this->disponible($this->otroSistema());
    }

    public function marcarIntento(Request $request): void
    {
        $request->session()->put(self::SESION_INTENTO, now()->getTimestamp());
    }

    /**
     * El otro sistema contestó (con un código o con «sin sesión»): el viaje de ida y vuelta terminó. Se borra la
     * marca de intento (la próxima visita al login vuelve a preguntar) y solo la visita inmediata siguiente se
     * salta la pregunta, para no ir y venir.
     */
    public function viajeCompletado(Request $request): void
    {
        $request->session()->forget(self::SESION_INTENTO);
        $this->omitirSiguiente($request);
    }

    /**
     * La próxima visita al login no pregunta (p. ej. justo después de cerrar sesión aquí).
     */
    public function omitirSiguiente(Request $request): void
    {
        $request->session()->put(self::SESION_OMITIR, true);
    }

    /**
     * Valor aleatorio que viaja en la pregunta y vuelve firmado en la respuesta.
     */
    public function nuevoEstado(Request $request): string
    {
        $estado = Str::random(40);
        $request->session()->put(self::SESION_ESTADO, $estado);

        return $estado;
    }

    public function estadoValido(Request $request, ?string $estado): bool
    {
        $esperado = $request->session()->pull(self::SESION_ESTADO);

        return is_string($esperado) && is_string($estado) && hash_equals($esperado, $estado);
    }

    public function vinculada(User $user): bool
    {
        return $user->sso_vinculado_at !== null;
    }

    /**
     * Recuerda (10 min) que esta persona llegó desde el otro sistema con este
     * correo; si ahora inicia sesión aquí con contraseña, se vincula.
     */
    public function pedirVinculo(Request $request, string $email, string $origen): void
    {
        $request->session()->put(self::SESION_VINCULO, [
            'email' => mb_strtolower($email),
            'origen' => $origen,
            'exp' => now()->addMinutes(10)->getTimestamp(),
        ]);
    }

    public function completarVinculo(Request $request, User $user): void
    {
        $pendiente = $request->session()->get(self::SESION_VINCULO);

        if (! is_array($pendiente)
            || ($pendiente['exp'] ?? 0) < now()->getTimestamp()
            || ($pendiente['email'] ?? null) !== mb_strtolower($user->email)) {
            $request->session()->forget(self::SESION_VINCULO);

            return;
        }

        // La contraseña sola no prueba que sea la dueña de la cuenta: si tiene 2FA, el vínculo se
        // completa al pasar el reto (se vuelve a intentar desde el reto y la configuración del 2FA).
        if (! $this->segundoFactorResuelto($request, $user)) {
            return;
        }

        $request->session()->forget(self::SESION_VINCULO);

        if ($this->vinculada($user)) {
            return;
        }

        $user->forceFill(['sso_vinculado_at' => now()])->save();

        activity('ecosistema')->causedBy($user)->performedOn($user)->event('sso_vinculo')
            ->withProperties(['origen' => $pendiente['origen'] ?? null, 'ip' => $request->ip()])
            ->log('Cuenta vinculada para el inicio de sesión único.');
    }

    private function segundoFactorResuelto(Request $request, User $user): bool
    {
        if (! config('auth.two_factor.enabled')) {
            return true;
        }

        return $user->hasTwoFactorEnabled() && (bool) $request->session()->get(EnsureTwoFactorIsVerified::SESSION_KEY);
    }

    /**
     * Si la sesión actual está completa, devuelve si pasó el 2FA; si no
     * (sin usuario, sin rol o con el 2FA pendiente), null: no se comparte.
     */
    public function sesionCompleta(Request $request): ?bool
    {
        $user = $request->user();

        if (! $user instanceof User || $user->roles()->doesntExist()) {
            return null;
        }

        if (! config('auth.two_factor.enabled')) {
            return false;
        }

        return $user->hasTwoFactorEnabled() && $request->session()->get(EnsureTwoFactorIsVerified::SESSION_KEY) ? true : null;
    }

    public function cuentaPara(string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        return $user && $user->roles()->exists() ? $user : null;
    }

    public function iniciar(Request $request, User $user, bool $mfa, string $origen): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if ($mfa && $user->hasTwoFactorEnabled()) {
            $request->session()->put(EnsureTwoFactorIsVerified::SESSION_KEY, true);
        }

        activity('ecosistema')->causedBy($user)->performedOn($user)->event('sso_login')
            ->withProperties(['origen' => $origen, 'dos_pasos_heredado' => $mfa && $user->hasTwoFactorEnabled(), 'ip' => $request->ip()])
            ->log('Inicio de sesión único desde '.$origen.'.');
    }

    private function disponible(?string $sistema): bool
    {
        if ($sistema === null) {
            return false;
        }

        return Cache::remember('ecosistema:disponible:'.$sistema, 60, function () use ($sistema): bool {
            try {
                return Http::timeout(2)->connectTimeout(2)->get($this->url($sistema, '/up'))->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }
}
