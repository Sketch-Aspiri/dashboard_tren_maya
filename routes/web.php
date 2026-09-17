<?php

use App\Http\Controllers\AsistenciaCapturaController;
use App\Http\Controllers\AsistenciaZonaController;
use App\Http\Controllers\ComisionadoFueraController;
use App\Http\Controllers\ComisionadoVisitanteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// This is a VPN-only, internal dashboard — no public unauthenticated
// splash page (see CLAUDE.md). Send guests to login and authenticated
// users straight to the dashboard.
Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
});

Route::middleware(['auth', 'verified', 'two-factor.verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Agenda Zona Oriente -> Personal: real data module (see CLAUDE.md and
    // app/Console/Commands/ImportAgendaZonaOrienteCommand.php).
    Route::prefix('agenda/personal')->name('agenda.personal.')->group(function () {
        Route::get('/', [EmpleadoController::class, 'index'])->name('index');
        Route::get('/data', [EmpleadoController::class, 'data'])
            ->middleware('throttle:agenda-personal-data')
            ->name('data');
        Route::get('/create', [EmpleadoController::class, 'create'])->name('create');
        Route::post('/', [EmpleadoController::class, 'store'])->name('store');
        Route::get('/{empleado}', [EmpleadoController::class, 'show'])->name('show');
        Route::get('/{empleado}/edit', [EmpleadoController::class, 'edit'])->name('edit');
        Route::put('/{empleado}', [EmpleadoController::class, 'update'])->name('update');
        Route::delete('/{empleado}', [EmpleadoController::class, 'destroy'])->name('destroy');
    });

    // Control de Asistencia Diaria -> Fase 1: captura de roster diario
    // (ver el plan aprobado y app/Policies/RegistroDiarioPolicy.php).
    Route::prefix('asistencia')->name('asistencia.')->group(function () {
        Route::get('/captura', [AsistenciaCapturaController::class, 'index'])->name('captura.index');
        Route::put('/captura', [AsistenciaCapturaController::class, 'update'])->name('captura.update');

        // Etapa 2 — comisionados_visitantes / comisionados_fuera (ver el
        // plan aprobado). Listados inline en la misma pantalla de captura,
        // sin index/show propios.
        Route::post('/captura/visitantes', [ComisionadoVisitanteController::class, 'store'])->name('captura.visitantes.store');
        Route::put('/captura/visitantes/{comisionadoVisitante}', [ComisionadoVisitanteController::class, 'update'])->name('captura.visitantes.update');
        Route::delete('/captura/visitantes/{comisionadoVisitante}', [ComisionadoVisitanteController::class, 'destroy'])->name('captura.visitantes.destroy');

        Route::post('/captura/comisionados', [ComisionadoFueraController::class, 'store'])->name('captura.comisionados.store');
        Route::put('/captura/comisionados/{comisionadoFuera}', [ComisionadoFueraController::class, 'update'])->name('captura.comisionados.update');
        Route::delete('/captura/comisionados/{comisionadoFuera}', [ComisionadoFueraController::class, 'destroy'])->name('captura.comisionados.destroy');

        // Etapa 3 — zona-wide "¿quién ya capturó hoy?" oversight board (ver
        // el plan aprobado). Distinct "zona" prefix segment from "captura"
        // above, so "/asistencia/zona/{estacion}" never collides with any
        // "/asistencia/captura/..." static route.
        Route::prefix('zona')->name('zona.')->group(function () {
            Route::get('/', [AsistenciaZonaController::class, 'index'])->name('index');
            Route::get('/data', [AsistenciaZonaController::class, 'data'])
                ->middleware('throttle:asistencia-zona-data')
                ->name('data');
            Route::get('/{estacion}', [AsistenciaZonaController::class, 'show'])->name('show');
        });
    });

    // Gestión de usuarios: Administrador-only account management panel
    // (see app/Policies/UserPolicy.php). Supersedes console-only
    // provisioning for day-to-day account create/edit/delete.
    Route::prefix('usuarios')->name('usuarios.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::get('/crear', [UserManagementController::class, 'create'])->name('create');
        Route::get('/{user}/editar', [UserManagementController::class, 'edit'])->name('edit');

        Route::middleware('throttle:usuarios-write')->group(function () {
            Route::post('/', [UserManagementController::class, 'store'])->name('store');
            Route::put('/{user}', [UserManagementController::class, 'update'])->name('update');
            Route::delete('/{user}', [UserManagementController::class, 'destroy'])->name('destroy');
        });
    });
});

require __DIR__.'/auth.php';
