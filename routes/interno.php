<?php

use App\Http\Controllers\Interno\ContextoFallaController;
use App\Http\Controllers\Interno\IaHerramientasController;
use App\Http\Middleware\EnsureIaHabilitada;
use Illuminate\Support\Facades\Route;

/*
 * Puente interno con Tickets: solo de servidor a servidor. Sin sesión,
 * cookies ni CSRF; cada petición la autentica VerificarPuente (firma HMAC,
 * fecha, nonce de un solo uso e IP). Se registra en bootstrap/app.php con el
 * prefijo `interno`.
 */
Route::get('contextos/{id}', ContextoFallaController::class)
    ->where('id', '[A-Za-z0-9]{40}')
    ->name('contextos');

/*
 * Herramientas para IA (MCP de Tickets): apagadas por omisión (EnsureIaHabilitada). Listar las herramientas y ejecutar
 * una; el cuerpo del POST va firmado por el puente. Tickets decide qué cliente MCP puede usarlas.
 */
Route::prefix('ia')->name('ia.')->middleware(EnsureIaHabilitada::class)->group(function () {
    Route::get('herramientas', [IaHerramientasController::class, 'index'])->name('herramientas');
    Route::post('herramientas/{nombre}', [IaHerramientasController::class, 'ejecutar'])
        ->where('nombre', '[a-z_]{3,40}')
        ->middleware('throttle:60,1')
        ->name('ejecutar');
});
