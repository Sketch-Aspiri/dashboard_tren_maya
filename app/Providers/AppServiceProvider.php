<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Roles allowed to view the Jefe de Zona dashboard. The dashboard has
     * no backing Eloquent model, so this is a plain Gate rather than a
     * model Policy, per
     * .claude/rules/code-style.md ("Gate::authorize(...)" is an accepted
     * alternative to a Policy for non-model actions).
     *
     * @var list<string>
     */
    private const DASHBOARD_ROLES = ['Jefe de Zona', 'Administrador'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureGates();
    }

    /**
     * Rate limits for internal JSON/AJAX endpoints, per
     * .claude/rules/api-conventions.md.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('agenda-personal-data', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Non-model authorization gates, backed by spatie/laravel-permission
     * roles, per .claude/rules/code-style.md.
     */
    private function configureGates(): void
    {
        Gate::define('view-dashboard', function (User $user): bool {
            return $user->hasAnyRole(self::DASHBOARD_ROLES);
        });
    }
}
