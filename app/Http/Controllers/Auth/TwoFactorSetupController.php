<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mandatory two-factor enrollment screen. A user without a confirmed
 * secret is routed here by EnsureTwoFactorIsVerified and cannot reach any
 * other authenticated route until enrollment is complete.
 */
class TwoFactorSetupController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('dashboard');
        }

        $secret = $this->service->pendingSecretFor($request);

        return view('auth.two-factor-setup', [
            'qrCodeSvg' => $this->service->qrCodeSvgFor($user, $secret),
            'secretKey' => $secret,
        ]);
    }

    public function store(TwoFactorCodeRequest $request): RedirectResponse
    {
        $secret = $request->session()->get(TwoFactorAuthenticationService::PENDING_SESSION_KEY);

        abort_unless(is_string($secret) && $secret !== '', 419);

        $request->verifyAgainstSecret($this->service, $secret);

        $this->service->enable($request->user(), $secret);

        $request->session()->forget(TwoFactorAuthenticationService::PENDING_SESSION_KEY);
        $request->session()->put(EnsureTwoFactorIsVerified::SESSION_KEY, true);

        // The real trust boundary in this app is "2FA-verified", not just
        // "password-authenticated" — regenerate the session id here too.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
