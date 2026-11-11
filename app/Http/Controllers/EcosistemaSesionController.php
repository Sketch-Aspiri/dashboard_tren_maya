<?php

namespace App\Http\Controllers;

use App\Http\Requests\EcosistemaSesionRequest;
use App\Services\Ecosistema\FirmaEcosistema;
use App\Services\Ecosistema\InicioUnicoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Inicio y cierre de sesión únicos con Tickets (ver InicioUnicoService).
 * Las tres rutas son GET porque llegan por redirección del navegador desde el
 * otro sistema; ninguna confía en datos sin firma.
 */
class EcosistemaSesionController extends Controller
{
    public function __construct(
        private readonly InicioUnicoService $sso,
        private readonly FirmaEcosistema $firma,
    ) {}

    /**
     * El otro sistema pregunta si aquí hay sesión.
     */
    public function preguntar(EcosistemaSesionRequest $request): RedirectResponse
    {
        $destino = (string) $request->validated('para');
        $mfa = $this->sso->activo() ? $this->sso->sesionCompleta($request) : null;
        $user = $request->user();

        if ($mfa === null || $user === null) {
            return redirect()->away($this->sso->url($destino, '/ecosistema/entrar?sin_sesion=1'));
        }

        // `vinculada`: el destino solo abre sesión sin contraseña si esta cuenta también lo está.
        $codigo = $this->firma->firmar($destino, FirmaEcosistema::ENTRAR, $user->email, $mfa, $request->validated('estado'), $this->sso->vinculada($user));

        return redirect()->away($this->sso->url($destino, '/ecosistema/entrar?codigo='.urlencode($codigo)));
    }

    /**
     * Respuesta del otro sistema: un código para entrar, o que allá no hay sesión.
     */
    public function entrar(EcosistemaSesionRequest $request): RedirectResponse
    {
        $this->sso->viajeCompletado($request);

        if ($request->user()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $datos = $this->firma->verificar($request->validated('codigo'), FirmaEcosistema::ENTRAR);

        // El código debe responder a la pregunta que hizo ESTE navegador.
        if ($datos === null || ! $this->sso->estadoValido($request, $datos['state'])) {
            return redirect()->route('login');
        }

        $user = $this->sso->cuentaPara($datos['email']);

        if ($user === null) {
            return redirect()->route('login')->with('status', __('Entraste a Tickets con :correo, pero esa cuenta no existe en el Dashboard. Inicia sesión con tu cuenta del Dashboard o pide acceso al administrador.', ['correo' => $datos['email']]));
        }

        // Sin contraseña solo si las DOS cuentas están vinculadas: el correo del origen puede haberse
        // editado sin verificar y el de aquí puede ser de otra persona.
        if (! $this->sso->vinculada($user) || ! $datos['lnk']) {
            $this->sso->pedirVinculo($request, $datos['email'], $datos['iss']);

            return redirect()->route('login')->with('status', __('Para unir tu cuenta del Dashboard con Tickets, inicia sesión aquí una sola vez. Después ya no te lo pediremos en ningún dispositivo.'));
        }

        $this->sso->iniciar($request, $user, $datos['mfa'], $datos['iss']);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Se cerró la sesión en el otro sistema: se cierra aquí si es la misma
     * persona, y se vuelve a su pantalla de login.
     */
    public function salir(EcosistemaSesionRequest $request): RedirectResponse
    {
        $datos = $this->firma->verificar($request->validated('codigo'), FirmaEcosistema::SALIR);
        $user = $request->user();

        if ($datos !== null && $user !== null && mb_strtolower($user->email) === $datos['email']) {
            activity()->causedBy($user)->performedOn($user)->event('logout')->log('User logged out (cierre único desde '.$datos['iss'].').');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Aquí NO se marca nada: este sistema quedó sin sesión y preguntar después no causa bucles. Si se marcara,
        // quien entra de inmediato por el otro sistema no entraría solo aquí durante varios minutos.
        $origen = $datos['iss'] ?? $this->sso->otroSistema();

        return $origen ? redirect()->away($this->sso->url($origen, '/login')) : redirect()->route('login');
    }
}
