<?php

namespace Tests\Concerns;

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;

/**
 * Since two-factor authentication is mandatory (CLAUDE.md), every Feature
 * test that exercises an authenticated route behind the
 * `two-factor.verified` middleware needs to simulate a completed OTP
 * challenge. Tests for the 2FA flow itself live in
 * tests/Feature/Auth/TwoFactorAuthenticationTest.php and intentionally do
 * NOT use this helper.
 */
trait InteractsWithTwoFactor
{
    /**
     * Act as a user who has already enrolled and verified two-factor
     * authentication for the current test session.
     */
    protected function actingAsTwoFactorVerified(?User $user = null): User
    {
        $user ??= User::factory()->create();

        if (! $user->hasTwoFactorEnabled()) {
            $user->forceFill([
                'google2fa_secret' => 'TESTSECRETKEYFORSPECS123456',
                'google2fa_enabled_at' => now(),
            ])->save();
        }

        $this->withSession([EnsureTwoFactorIsVerified::SESSION_KEY => true])
            ->actingAs($user);

        return $user;
    }
}
