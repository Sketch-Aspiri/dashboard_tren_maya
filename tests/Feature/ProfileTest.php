<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAsTwoFactorVerified();

        $response = $this->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->actingAsTwoFactorVerified();

        $response = $this->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_changing_the_email_requires_the_current_password_and_unlinks_the_account(): void
    {
        $user = $this->actingAsTwoFactorVerified();
        $user->forceFill(['sso_vinculado_at' => now()])->save();
        $original = $user->email;

        $this->patch('/profile', ['name' => 'Test User', 'email' => 'nuevo@example.com'])->assertSessionHasErrors('current_password');
        $this->patch('/profile', ['name' => 'Test User', 'email' => 'nuevo@example.com', 'current_password' => 'incorrecta'])->assertSessionHasErrors('current_password');
        $this->assertSame($original, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->sso_vinculado_at);

        $this->patch('/profile', ['name' => 'Test User', 'email' => 'nuevo@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('nuevo@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->sso_vinculado_at);
    }

    public function test_the_name_can_change_without_the_password_when_the_email_is_the_same(): void
    {
        $user = $this->actingAsTwoFactorVerified();
        $user->forceFill(['sso_vinculado_at' => now()])->save();

        $this->patch('/profile', ['name' => 'Otro Nombre', 'email' => $user->email])->assertSessionHasNoErrors();

        $this->assertSame('Otro Nombre', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->sso_vinculado_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = $this->actingAsTwoFactorVerified();

        $response = $this->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = $this->actingAsTwoFactorVerified();

        $response = $this->delete('/profile', [
            'password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = $this->actingAsTwoFactorVerified();

        $response = $this
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
