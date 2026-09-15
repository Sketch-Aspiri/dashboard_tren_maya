<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Registration itself is unreachable over HTTP in v1 (routes/auth.php
 * disables `/register` — single-user model, see CLAUDE.md), so this
 * exercises RegisteredUserController::store() directly to confirm the
 * audit-logging fix still holds for when the route is re-enabled.
 */
class RegisteredUserControllerAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_user_is_logged(): void
    {
        $request = RegisterUserRequest::create('/register', 'POST', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Correct-horse-battery-staple-1',
            'password_confirmation' => 'Correct-horse-battery-staple-1',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $controller = $this->app->make(RegisteredUserController::class);
        $controller->store($request);

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'user_registered')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
    }
}
