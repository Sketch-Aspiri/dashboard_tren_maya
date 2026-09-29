<?php

use App\Http\Controllers\AsistenciaCapturaController;
use App\Http\Controllers\AsistenciaDocumentoController;
use App\Http\Controllers\AsistenciaZonaController;
use App\Http\Controllers\ComisionadoFueraController;
use App\Http\Controllers\ComisionadoVisitanteController;
use App\Http\Controllers\ControlesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\EstadisticaController;
use App\Http\Controllers\GastoEnergeticoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolVacacionesController;
use App\Http\Controllers\SeccionPendienteController;
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

    // Agenda Zona Oriente -> Rol de vacaciones: consulta del rol anual
    // (solo lectura; se carga con app:import-rol-vacaciones).
    Route::get('agenda/vacaciones', [RolVacacionesController::class, 'index'])->name('agenda.vacaciones.index');

    // Control de Asistencia Diaria -> Fase 1: captura de roster diario
    // (ver el plan aprobado y app/Policies/RegistroDiarioPolicy.php).
    Route::prefix('asistencia')->name('asistencia.')->group(function () {
        Route::get('/captura', [AsistenciaCapturaController::class, 'index'])->name('captura.index');
        Route::put('/captura', [AsistenciaCapturaController::class, 'update'])->name('captura.update');

        // Oficios autogenerados (.docx + .pdf): generación y descarga. La
        // generación convierte con LibreOffice, por eso va con throttle propio.
        Route::post('/captura/oficio', [AsistenciaDocumentoController::class, 'storeEstacion'])
            ->middleware('throttle:asistencia-documentos')
            ->name('captura.oficio.store');
        Route::get('/documentos/{documento}/{formato}', [AsistenciaDocumentoController::class, 'download'])
            ->middleware('throttle:60,1')
            ->whereIn('formato', ['docx', 'pdf'])
            ->name('documentos.download');

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
            Route::post('/oficio', [AsistenciaDocumentoController::class, 'storeZona'])
                ->middleware('throttle:asistencia-documentos')
                ->name('oficio.store');
            Route::get('/{estacion}', [AsistenciaZonaController::class, 'show'])->name('show');
        });
    });

    // Estadísticas -> Gasto energético: resumen de la zona, captura/corrección
    // por estación y baja de un pago (carga inicial con
    // app:import-gasto-energetico). Declarado ANTES del grupo "estadisticas"
    // de abajo para que "/estadisticas/gasto-energetico" no lo capture la
    // ruta "/estadisticas/{estacion}".
    Route::prefix('estadisticas/gasto-energetico')->name('estadisticas.gasto-energetico.')->group(function () {
        Route::get('/', [GastoEnergeticoController::class, 'index'])->name('index');
        Route::get('/{estacion}', [GastoEnergeticoController::class, 'show'])->name('show');

        Route::middleware('throttle:estadisticas-write')->group(function () {
            Route::put('/{estacion}', [GastoEnergeticoController::class, 'update'])->name('update');
            Route::delete('/{estacion}/pagos/{pago}', [GastoEnergeticoController::class, 'destroy'])->name('destroy');
        });
    });

    // Módulo "Estadísticas": flujo de pasajeros / boletos vendidos por
    // estación (ver el plan aprobado y app/Policies/EstadisticaDiariaPolicy.php).
    Route::prefix('estadisticas')->name('estadisticas.')->group(function () {
        Route::get('/', [EstadisticaController::class, 'index'])->name('index');
        Route::get('/{estacion}', [EstadisticaController::class, 'show'])->name('show');

        Route::middleware('throttle:estadisticas-write')->group(function () {
            Route::put('/{estacion}', [EstadisticaController::class, 'update'])->name('update');
            Route::delete('/{estacion}/registros/{registro}', [EstadisticaController::class, 'destroy'])->name('destroy');
        });
    });

    // Módulo "Controles": inventario de escaleras eléctricas y elevadores,
    // más el estatus de vías y andenes (ver app/Console/Commands/ImportControlesCommand.php y
    // app/Policies/EscaleraElectricaPolicy.php / ElevadorPolicy.php).
    Route::prefix('controles')->name('controles.')->group(function () {
        Route::get('/escaleras-electricas', [ControlesController::class, 'escalerasElectricas'])->name('escaleras-electricas.index');
        Route::get('/escaleras-electricas/{escalera}/editar', [ControlesController::class, 'editarEscaleraElectrica'])->name('escaleras-electricas.edit');
        Route::get('/elevadores', [ControlesController::class, 'elevadores'])->name('elevadores.index');
        Route::get('/elevadores/{elevador}/editar', [ControlesController::class, 'editarElevador'])->name('elevadores.edit');
        Route::get('/estatus-vias-andenes', [ControlesController::class, 'estatusViasAndenes'])->name('estatus-vias-andenes.index');
        Route::get('/estatus-vias-andenes/{estatusViaAnden}/editar', [ControlesController::class, 'editarEstatusViaAnden'])->name('estatus-vias-andenes.edit');

        Route::middleware('throttle:controles-write')->group(function () {
            Route::put('/escaleras-electricas/{escalera}', [ControlesController::class, 'actualizarEscaleraElectrica'])->name('escaleras-electricas.update');
            Route::put('/elevadores/{elevador}', [ControlesController::class, 'actualizarElevador'])->name('elevadores.update');
            Route::put('/estatus-vias-andenes/{estatusViaAnden}', [ControlesController::class, 'actualizarEstatusViaAnden'])->name('estatus-vias-andenes.update');
        });
    });

    // Conceptos del PPT "Dashboard Tren Maya" sin módulo todavía: página
    // "pendiente de agregar información" (config/navegacion.php).
    Route::get('/secciones/{grupo}/{seccion}', [SeccionPendienteController::class, 'show'])->name('secciones.show');

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
