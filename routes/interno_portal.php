<?php

use App\Http\Controllers\Interno\PortalCatalogoController;
use App\Http\Controllers\Interno\PortalCuentasController;
use App\Http\Controllers\Interno\PortalFotoController;
use Illuminate\Support\Facades\Route;

/*
 * Puente con el portal: solo de servidor a servidor, firmado con el secreto propio del portal
 * (VerificarPuentePortal). Registrado en bootstrap/app.php con el prefijo `interno/portal`.
 */
Route::get('catalogo', PortalCatalogoController::class)->name('catalogo');
Route::get('cuentas', [PortalCuentasController::class, 'index'])->name('cuentas.index');
Route::post('cuentas', [PortalCuentasController::class, 'store'])->name('cuentas.store');
Route::put('cuentas/{portalUserId}', [PortalCuentasController::class, 'update'])->name('cuentas.update');

// Foto de perfil única: el portal la lee (herencia inicial) y la reparte.
Route::get('avatar', [PortalFotoController::class, 'show'])->name('avatar.show');
Route::put('avatar', [PortalFotoController::class, 'update'])->name('avatar.update');
Route::delete('avatar', [PortalFotoController::class, 'destroy'])->name('avatar.destroy');
