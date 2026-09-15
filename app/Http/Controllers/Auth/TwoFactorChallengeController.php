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
 * Per-session OTP challenge for an already-enrolled user. Reached once per
 * session (the flag is cleared on logout) — see EnsureTwoFactorIsVerified.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        if ($request->session()->get(EnsureTwoFactorIsVerified::SESSION_KEY)) {
            return redirect()->route('dashboard');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $request->user();

        $request->verifyAgainstSecret($this->service, (string) $user->google2fa_secret);

        $request->session()->put(EnsureTwoFactorIsVerified::SESSION_KEY, true);

        // The real trust boundary in this app is "2FA-verified", not just
        // "password-authenticated" — regenerate the session id here too.
        $request->session()->regenerate();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('two_factor_verified')
            ->log('Two-factor challenge passed.');

        return redirect()->intended(route('dashboard'));
    }
}
