<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Authorization coverage for the dashboard's `view-dashboard` Gate
 * (App\Providers\AppServiceProvider), added per the code-reviewer /
 * security-auditor punch list: every authenticated route needs an
 * explicit role-backed authorization check, not just "logged in".
 */
class DashboardTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_zone_chief_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Jefe de Zona');
        $this->actingAsTwoFactorVerified($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_administrador_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Administrador');
        $this->actingAsTwoFactorVerified($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_user_without_a_role_cannot_view_the_dashboard(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertForbidden();
    }
}
