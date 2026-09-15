<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_successful_login_is_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'login_succeeded')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
    }

    public function test_failed_login_is_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $activity = Activity::query()
            ->where('event', 'login_failed')
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame($user->email, $activity->properties->get('email'));
    }

    public function test_logout_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'logout')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
    }
}
