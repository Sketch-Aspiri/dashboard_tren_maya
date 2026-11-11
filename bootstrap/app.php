<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Http\Middleware\VerificarPuente;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Puente interno con Tickets: fuera del grupo `web` (sin sesión ni CSRF).
        then: function () {
            Route::middleware(['throttle:240,1', VerificarPuente::class])
                ->prefix('interno')
                ->name('interno.')
                ->group(base_path('routes/interno.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Los proxies de confianza se fijan en AppServiceProvider desde TRUSTED_PROXIES (config/proxies.php): con `*`
        // cualquiera podria falsear su IP con X-Forwarded-For y esquivar los limites de intentos.

        $middleware->alias([
            'two-factor.verified' => EnsureTwoFactorIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Nunca devolver al formulario (ni a la sesión) el secreto OAuth tras un error de validación.
        $exceptions->dontFlash(['client_secret']);
    })->create();
