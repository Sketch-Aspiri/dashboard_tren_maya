<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Asistente\ClienteClaude;
use App\Services\Asistente\ModeloLenguaje;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\ConvertidorPdfLibreOffice;
use App\Services\Secciones\ExtractorDocumentos;
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
        $this->app->singleton(ConvertidorPdf::class, fn () => new ConvertidorPdfLibreOffice(
            binario: (string) config('asistencia_documentos.soffice_path'),
            timeoutSegundos: (int) config('asistencia_documentos.conversion_timeout'),
        ));

        $this->app->singleton(ModeloLenguaje::class, ClienteClaude::class);

        // Una instancia por petición: comparte la caché de tablas leídas
        // entre ArchivoService e ImportacionService (no relee el archivo).
        $this->app->scoped(ExtractorDocumentos::class);
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
        // Generating an oficio launches LibreOffice (several seconds of CPU),
        // so it is limited far below the read endpoints.
        RateLimiter::for('asistencia-documentos', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

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

        // Secciones dinámicas: menú, columnas, filas, cargas.
        RateLimiter::for('secciones-write', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Subida de documentos (hasta 100 MB c/u; leer tablas/texto cuesta CPU).
        RateLimiter::for('secciones-archivos', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Vista previa de documentos (lee Excel/Word en el servidor).
        RateLimiter::for('secciones-vista-previa', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Todo lo que llama a la Claude API (asistente y "Extraer con IA"):
        // por minuto y tope diario, para acotar costo y abuso.
        RateLimiter::for('ia', function (Request $request) {
            $clave = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute((int) config('services.anthropic.asistente_por_minuto'))->by('min:'.$clave),
                Limit::perDay((int) config('services.anthropic.asistente_por_dia'))->by('dia:'.$clave),
            ];
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

        // Asistente de preguntas del dashboard (Claude API): consulta toda la
        // información de la zona, así que solo la Jefatura de Zona.
        Gate::define('usar-asistente', function (User $user): bool {
            return $user->hasAnyRole(self::ZONA_ROLES);
        });
    }
}
