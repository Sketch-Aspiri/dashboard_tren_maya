<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mandatory two-factor authentication for every authenticated
 * request, per CLAUDE.md "2FA obligatorio para el login".
 *
 * A user without a confirmed TOTP secret is redirected to the mandatory
 * enrollment screen; a user with a secret who has not verified an OTP in
 * the current session is redirected to the verification challenge.
 */
class EnsureTwoFactorIsVerified
{
    /**
     * Session key marking that the current session has passed the OTP
     * challenge for the authenticated user.
     */
    public const SESSION_KEY = 'two_factor_verified';

    /**
     * Route names exempt from this gate (the enrollment/challenge screens
     * themselves, to avoid an infinite redirect loop).
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTE_NAMES = [
        'two-factor.setup',
        'two-factor.setup.store',
        'two-factor.verify',
        'two-factor.verify.store',
        'logout',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $this->isExemptRoute()) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        if (! $request->session()->get(self::SESSION_KEY)) {
            return redirect()->route('two-factor.verify');
        }

        return $next($request);
    }

    private function isExemptRoute(): bool
    {
        $name = Route::currentRouteName();

        return $name !== null && in_array($name, self::EXEMPT_ROUTE_NAMES, true);
    }
}
