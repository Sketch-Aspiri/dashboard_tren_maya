<?php

use App\Http\Controllers\Interno\ContextoFallaController;
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
