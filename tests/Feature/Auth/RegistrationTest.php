<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public self-registration is disabled for v1 (see routes/auth.php and
 * CLAUDE.md — "Administración de usuarios preparado a futuro, no activo en
 * v1"). RegisteredUserController and its views are kept for a future
 * phase, but the routes are commented out, so the original Breeze
 * boilerplate tests exercising `/register` no longer apply. This test
 * documents and locks in that decision instead of exercising a disabled
 * route.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_routes_are_disabled(): void
    {
        $this->get('/register')->assertStatus(404);
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(404);
    }
}
