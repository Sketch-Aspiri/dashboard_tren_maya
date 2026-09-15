<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Covers the interactive provisioning command that replaces the removed
 * DatabaseSeeder-created Jefe de Zona account (CLAUDE.md /
 * .claude/rules/server-conventions.md — never a known/default password).
 */
class CreateZoneChiefCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_it_provisions_a_zone_chief_account_with_prompted_credentials(): void
    {
        $this->artisan('app:create-zone-chief')
            ->expectsQuestion('Full name', 'Jefe de Zona')
            ->expectsQuestion('Email address', 'jefe@example.com')
            ->expectsQuestion('Password', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirm password', 'Correct-horse-battery-staple-1')
            ->assertExitCode(0);

        $user = User::where('email', 'jefe@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('Jefe de Zona'));
        $this->assertTrue(Hash::check('Correct-horse-battery-staple-1', $user->password));
    }

    public function test_it_fails_when_password_confirmation_does_not_match(): void
    {
        $this->artisan('app:create-zone-chief')
            ->expectsQuestion('Full name', 'Jefe de Zona')
            ->expectsQuestion('Email address', 'jefe@example.com')
            ->expectsQuestion('Password', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirm password', 'a-different-password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'jefe@example.com']);
    }

    public function test_it_fails_when_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'jefe@example.com']);

        $this->artisan('app:create-zone-chief')
            ->expectsQuestion('Full name', 'Jefe de Zona')
            ->expectsQuestion('Email address', 'jefe@example.com')
            ->expectsQuestion('Password', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirm password', 'Correct-horse-battery-staple-1')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('email', 'jefe@example.com')->count());
    }

    public function test_it_logs_the_provisioning_as_an_activity(): void
    {
        $this->artisan('app:create-zone-chief')
            ->expectsQuestion('Full name', 'Jefe de Zona')
            ->expectsQuestion('Email address', 'jefe@example.com')
            ->expectsQuestion('Password', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirm password', 'Correct-horse-battery-staple-1')
            ->assertExitCode(0);

        $user = User::where('email', 'jefe@example.com')->firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'zone_chief_provisioned')
            ->first();

        $this->assertNotNull($activity);
    }
}
