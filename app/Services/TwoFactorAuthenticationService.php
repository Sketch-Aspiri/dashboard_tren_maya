<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Wraps pragmarx/google2fa(-qrcode) to keep enrollment/QR-generation logic
 * out of the controllers, per this project's thin-controller convention.
 */
final class TwoFactorAuthenticationService
{
    /**
     * Session key holding a not-yet-confirmed secret during enrollment.
     */
    public const PENDING_SESSION_KEY = 'two_factor_setup_secret';

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Get the pending enrollment secret for the current session, generating
     * and storing a new one if none exists yet.
     */
    public function pendingSecretFor(Request $request): string
    {
        $secret = $request->session()->get(self::PENDING_SESSION_KEY);

        if (! is_string($secret) || $secret === '') {
            $secret = $this->google2fa->generateSecretKey();
            $request->session()->put(self::PENDING_SESSION_KEY, $secret);
        }

        return $secret;
    }

    /**
     * Render an inline SVG QR code for the given user/secret pair, scannable
     * by any TOTP authenticator app.
     */
    public function qrCodeSvgFor(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeInline(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Verify a one-time password against a raw (not-yet-persisted) secret.
     */
    public function verify(string $secret, string $oneTimePassword): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $oneTimePassword);
    }

    /**
     * Persist a confirmed secret on the user and log the enrollment.
     */
    public function enable(User $user, string $secret): void
    {
        $user->forceFill([
            'google2fa_secret' => $secret,
            'google2fa_enabled_at' => now(),
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('two_factor_enabled')
            ->log('Two-factor authentication enabled.');
    }
}
