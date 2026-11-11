<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Ecosistema\FirmaEcosistema;
use App\Services\Ecosistema\InicioUnicoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly InicioUnicoService $sso,
        private readonly FirmaEcosistema $firma,
    ) {}

    /**
     * Display the login view. Antes, una vez, se le pregunta a Tickets si en
     * este dispositivo ya hay sesión (inicio de sesión único del ecosistema).
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->sso->debePreguntar($request)) {
            $this->sso->marcarIntento($request);

            $estado = $this->sso->nuevoEstado($request);

            return redirect()->away($this->sso->url((string) $this->sso->otroSistema(), '/ecosistema/sso?para='.config('ecosistema.sistema').'&estado='.$estado));
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Si llegó desde Tickets con este mismo correo, la cuenta queda vinculada
        // y el inicio de sesión único ya no le pedirá contraseña.
        $this->sso->completarVinculo($request, $request->user());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        Auth::guard('web')->logout();

        if ($user) {
            activity()
                ->causedBy($user)
                ->performedOn($user)
                ->event('logout')
                ->log('User logged out.');
        }

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Cierre único: también se cierra la sesión de la misma persona en Tickets, que
        // después regresa al login de aquí (sin volver a preguntarle por la sesión).
        if ($user && $this->sso->activo()) {
            $this->sso->marcarIntento($request);
            $otro = (string) $this->sso->otroSistema();
            $codigo = $this->firma->firmar($otro, FirmaEcosistema::SALIR, $user->email, false);

            return redirect()->away($this->sso->url($otro, '/ecosistema/salir?codigo='.urlencode($codigo)));
        }

        return redirect('/');
    }
}
