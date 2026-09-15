<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Mandatory 2FA enforcement — CLAUDE.md "2FA obligatorio para el login".
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function currentOtpFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_unenrolled_user_is_redirected_to_two_factor_setup_from_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('two-factor.setup'));
    }

    public function test_enrolled_user_who_has_not_verified_this_session_is_redirected_to_challenge(): void
    {
        $user = User::factory()->create([
            'google2fa_secret' => 'TESTSECRETKEYFORSPECS123456',
            'google2fa_enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('two-factor.verify'));
    }

    public function test_setup_screen_can_be_rendered_for_an_unenrolled_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('two-factor.setup'));

        $response->assertOk();
        $response->assertViewHas('secretKey');
        $response->assertViewHas('qrCodeSvg');
    }

    public function test_user_can_complete_enrollment_with_a_valid_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('two-factor.setup'));

        $secret = session(TwoFactorAuthenticationService::PENDING_SESSION_KEY);
        $this->assertIsString($secret);

        $response = $this->post(route('two-factor.setup.store'), [
            'one_time_password' => $this->currentOtpFor($secret),
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertTrue(session(EnsureTwoFactorIsVerified::SESSION_KEY));
    }

    public function test_enrollment_fails_with_an_incorrect_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('two-factor.setup'));

        $response = $this->post(route('two-factor.setup.store'), [
            'one_time_password' => '000000',
        ]);

        $response->assertInvalid(['one_time_password']);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enrolled_user_can_pass_the_challenge_with_a_valid_code(): void
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user = User::factory()->create([
            'google2fa_secret' => $secret,
            'google2fa_enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('two-factor.verify.store'), [
            'one_time_password' => $this->currentOtpFor($secret),
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(session(EnsureTwoFactorIsVerified::SESSION_KEY));
    }

    public function test_session_is_regenerated_after_completing_two_factor_enrollment(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('two-factor.setup'));
        $originalSessionId = session()->getId();

        $secret = session(TwoFactorAuthenticationService::PENDING_SESSION_KEY);

        $this->post(route('two-factor.setup.store'), [
            'one_time_password' => $this->currentOtpFor($secret),
        ]);

        $this->assertNotSame($originalSessionId, session()->getId());
    }

    public function test_session_is_regenerated_after_passing_the_two_factor_challenge(): void
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user = User::factory()->create([
            'google2fa_secret' => $secret,
            'google2fa_enabled_at' => now(),
        ]);

        $this->actingAs($user);
        $originalSessionId = session()->getId();

        $this->post(route('two-factor.verify.store'), [
            'one_time_password' => $this->currentOtpFor($secret),
        ]);

        $this->assertNotSame($originalSessionId, session()->getId());
    }

    public function test_challenge_fails_with_an_incorrect_code(): void
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user = User::factory()->create([
            'google2fa_secret' => $secret,
            'google2fa_enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('two-factor.verify.store'), [
            'one_time_password' => '000000',
        ]);

        $response->assertInvalid(['one_time_password']);
        $this->assertNull(session(EnsureTwoFactorIsVerified::SESSION_KEY));
    }

    public function test_challenge_is_rate_limited_after_repeated_failures(): void
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user = User::factory()->create([
            'google2fa_secret' => $secret,
            'google2fa_enabled_at' => now(),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)->post(route('two-factor.verify.store'), [
                'one_time_password' => '000000',
            ]);
        }

        $response = $this->actingAs($user)->post(route('two-factor.verify.store'), [
            'one_time_password' => '000000',
        ]);

        $response->assertSessionHasErrors(['one_time_password']);
        $this->assertStringContainsString(
            'seconds',
            session('errors')->get('one_time_password')[0],
        );
    }
}
