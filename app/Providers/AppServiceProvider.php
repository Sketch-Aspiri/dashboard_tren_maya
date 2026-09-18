<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Roles allowed to view the Jefe de Zona dashboard and the zona-wide
     * attendance oversight board (Etapa 3 — Control de Asistencia Diaria).
     * Neither has a backing Eloquent model, so both are plain Gates rather
     * than model Policies, per .claude/rules/code-style.md
     * ("Gate::authorize(...)" is an accepted alternative to a Policy for
     * non-model actions). "Estación" is explicitly excluded from both —
     * a station account only ever sees its own capture screen.
     *
     * @var list<string>
     */
    private const ZONA_ROLES = ['Jefe de Zona', 'Administrador'];

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
        $this->configurePasswordDefaults();
        $this->configureRateLimiting();
        $this->configureGates();
    }

    /**
     * Single source of truth for password strength across every call site
     * (the web account-management panel and the interactive
     * app:create-zone-chief / app:create-estacion-account console
     * commands all use Password::defaults()) — this system holds
     * CURP/RFC/NSS-level PII, so the bare Laravel default (8 chars, no
     * other requirement) isn't strong enough.
     */
    private function configurePasswordDefaults(): void
    {
        // No ->uncompromised(): it calls the HaveIBeenPwned API on every
        // password validation (~1.5-4s each in testing) — an external
        // network dependency this VPN-only system with a handful of
        // admin-provisioned accounts doesn't need to take on for its
        // threat model. The local rules below are strong on their own.
        Password::defaults(fn () => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols());
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

        // Account provisioning/edits/deletes — the closest thing this app
        // has to an auth entry point besides login itself, per the
        // security review of the Gestión de usuarios panel.
        RateLimiter::for('usuarios-write', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Zona-wide "who has captured today" board (Etapa 3 — Control de
        // Asistencia Diaria).
        RateLimiter::for('asistencia-zona-data', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Módulo "Estadísticas" — captura mensual (update) y borrado
        // (destroy) de registros. Same treatment as usuarios-write: a
        // state-changing route, not just a read.
        RateLimiter::for('estadisticas-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Non-model authorization gates, backed by spatie/laravel-permission
     * roles, per .claude/rules/code-style.md.
     */
    private function configureGates(): void
    {
        Gate::define('view-dashboard', function (User $user): bool {
            return $user->hasAnyRole(self::ZONA_ROLES);
        });

        // Etapa 3 — Control de Asistencia Diaria: zona-wide "¿quién ya
        // capturó hoy?" oversight board. "Estación" is deliberately not in
        // ZONA_ROLES — it can only ever see its own station's capture
        // screen, never the zona-wide board.
        Gate::define('view-asistencia-zona', function (User $user): bool {
            return $user->hasAnyRole(self::ZONA_ROLES);
        });
    }
}
