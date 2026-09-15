<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default Laravel "welcome" splash page is removed: this is a
 * VPN-only internal dashboard with no public unauthenticated content
 * (see CLAUDE.md). `/` now redirects to login or the dashboard.
 */
class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_is_redirected_to_dashboard(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertRedirect(route('dashboard'));
    }
}
