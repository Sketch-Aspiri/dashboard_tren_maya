<?php

namespace App\Providers;

use App\Console\Commands\NubeDiagnosticoCommand;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Asistente\ClienteClaude;
use App\Services\Asistente\ModeloLenguaje;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\ConvertidorPdfLibreOffice;
use App\Services\Documentos\UnidorPdf;
use App\Services\Sat\ConsultorSat;
use App\Services\Sat\ConsultorSatPhpCfdi;
use App\Services\Documentos\UnidorPdfGhostscript;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Secciones\ExtractorDocumentos;
use App\Services\Secciones\PermisosPorNombreService;
use App\Services\Secciones\VisibilidadSecciones;
use App\Support\Roles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
    private const ZONA_ROLES = Roles::ZONA;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConvertidorPdf::class, fn () => new ConvertidorPdfLibreOffice(
            binario: (string) config('asistencia_documentos.soffice_path'),
            timeoutSegundos: (int) config('asistencia_documentos.conversion_timeout'),
        ));

        $this->app->singleton(UnidorPdf::class, fn () => new UnidorPdfGhostscript((string) config('insumos.comprobacion.gs_path')));

        $this->app->singleton(ConsultorSat::class, fn () => new ConsultorSatPhpCfdi((int) config('insumos.sat.timeout')));

        $this->app->singleton(ModeloLenguaje::class, ClienteClaude::class);

        // Hoy solo enlaces públicos; las cuentas OAuth (Graph, Drive, Dropbox) se enlazan por origen en F2+.
        $this->app->bind(ProveedorNube::class, EnlacePublico::class);

        // Una instancia por petición: comparte la caché de tablas leídas
        // entre ArchivoService e ImportacionService (no relee el archivo).
        $this->app->scoped(ExtractorDocumentos::class);
        $this->app->scoped(VisibilidadSecciones::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Subsecciones y hojas que nombran a una estación le dan Editar solas (una sola pasada por petición/proceso).
        $pendiente = false;
        $programar = function () use (&$pendiente): void {
            if ($pendiente) {
                return;
            }

            $pendiente = true;
            $this->app->terminating(function () use (&$pendiente): void {
                $pendiente = false;
                $this->app->make(PermisosPorNombreService::class)->aplicar();
            });
        };
        Seccion::created($programar);
        Seccion::updated(fn (Seccion $s) => $s->wasChanged(['nombre', 'parent_id']) ? $programar() : null);
        SeccionTabla::created($programar);
        SeccionTabla::updated(fn (SeccionTabla $t) => $t->wasChanged(['nombre', 'seccion_id']) ? $programar() : null);

        $this->configurePasswordDefaults();
        $this->configureRateLimiting();
        $this->configureGates();
        $this->registrarLatidoDelWorker();
    }

    /**
     * Cada trabajo que un worker termina deja su hora en caché: `php artisan
     * nube:diagnostico` la usa para decir si el worker de cola está vivo.
     */
    private function registrarLatidoDelWorker(): void
    {
        Event::listen(JobProcessed::class, fn () => Cache::put(NubeDiagnosticoCommand::CLAVE_WORKER, now()->timestamp, now()->addDays(7)));
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

        // Hoja de cálculo: la cuadrícula guarda por lotes mientras se edita
        // (el navegador agrupa los cambios), así que el tope es más holgado
        // que el de secciones-write.
        RateLimiter::for('secciones-hoja', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });

        // Subida de documentos (hasta 100 MB c/u; leer tablas/texto cuesta CPU).
        RateLimiter::for('secciones-archivos', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Vista previa de documentos (lee Excel/Word en el servidor).
        RateLimiter::for('secciones-vista-previa', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Conexiones de nube (solo Administrador): credenciales, inicio y
        // retorno del OAuth, desconexión. Pocas operaciones legítimas.
        RateLimiter::for('nube-oauth', function (Request $request) {
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

        // Descarga del consolidado mensual de insumos (arma varios libros de Excel).
        RateLimiter::for('insumos-export', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Módulo "Solicitud de insumos" — captura, envío y revisión.
        RateLimiter::for('insumos-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Non-model authorization gates, backed by spatie/laravel-permission
     * roles, per .claude/rules/code-style.md.
     */
    private function configureGates(): void
    {
        // Inicio propio de una cuenta Estación (el dashboard de zona queda cerrado para ella).
        Gate::define('view-inicio-estacion', fn (User $user): bool => $user->hasRole(Roles::ESTACION));

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
            return $user->hasAnyRole(Roles::JEFATURA);
        });
    }
}
