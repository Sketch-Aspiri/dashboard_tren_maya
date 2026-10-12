<?php

use App\Http\Controllers\AsistenciaCapturaController;
use App\Http\Controllers\AsistenciaDocumentoController;
use App\Http\Controllers\AsistenciaZonaController;
use App\Http\Controllers\ComisionadoFueraController;
use App\Http\Controllers\ComisionadoVisitanteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\PersonalImportacionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Secciones\AdministrarMenuController;
use App\Http\Controllers\Secciones\MenuGrupoController;
use App\Http\Controllers\Secciones\SeccionArchivoController;
use App\Http\Controllers\Secciones\SeccionColumnaController;
use App\Http\Controllers\Secciones\SeccionController;
use App\Http\Controllers\Secciones\SeccionExportacionController;
use App\Http\Controllers\Secciones\SeccionFilaController;
use App\Http\Controllers\Secciones\SeccionImportacionController;
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

        // Importar el Excel de la base de datos arrastrándolo (vista previa
        // y luego aplicar). Antes de '/{empleado}' para no chocar con él.
        Route::get('/importar', [PersonalImportacionController::class, 'show'])->name('importar.show');
        Route::post('/importar', [PersonalImportacionController::class, 'store'])
            ->middleware('throttle:secciones-archivos')
            ->name('importar.store');
        Route::post('/importar/aplicar', [PersonalImportacionController::class, 'aplicar'])
            ->middleware('throttle:secciones-write')
            ->name('importar.aplicar');
        Route::delete('/importar', [PersonalImportacionController::class, 'destroy'])->name('importar.destroy');
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

    // Secciones dinámicas del menú (tabla configurable + documentos +
    // cargas por arrastre). Las rutas anidadas usan scopeBindings(): una
    // fila/columna/archivo/carga solo resuelve dentro de SU sección.
    Route::prefix('secciones')->name('secciones.')->group(function () {
        Route::get('/administrar', [AdministrarMenuController::class, 'index'])->name('administrar');

        Route::middleware('throttle:secciones-write')->group(function () {
            Route::post('/grupos', [MenuGrupoController::class, 'store'])->name('grupos.store');
            Route::put('/grupos/{grupo}', [MenuGrupoController::class, 'update'])->name('grupos.update');
            Route::post('/grupos/{grupo}/mover', [MenuGrupoController::class, 'mover'])->name('grupos.mover');
            Route::delete('/grupos/{grupo}', [MenuGrupoController::class, 'destroy'])->name('grupos.destroy');

            Route::post('/', [SeccionController::class, 'store'])->name('store');
        });

        Route::get('/{seccion}', [SeccionController::class, 'show'])->name('show');
        Route::get('/{seccion}/exportar', SeccionExportacionController::class)->name('exportar');
        Route::get('/{seccion}/columnas', [SeccionColumnaController::class, 'index'])->name('columnas.index');

        Route::scopeBindings()->group(function () {
            Route::get('/{seccion}/archivos/{archivo}', [SeccionArchivoController::class, 'show'])->name('archivos.show');
            Route::get('/{seccion}/importaciones/{importacion}', [SeccionImportacionController::class, 'show'])->name('importaciones.show');

            Route::middleware('throttle:secciones-write')->group(function () {
                Route::put('/{seccion}', [SeccionController::class, 'update'])->name('update');
                Route::post('/{seccion}/mover', [SeccionController::class, 'mover'])->name('mover');
                Route::delete('/{seccion}', [SeccionController::class, 'destroy'])->name('destroy');

                Route::post('/{seccion}/columnas', [SeccionColumnaController::class, 'store'])->name('columnas.store');
                Route::put('/{seccion}/columnas/{columna}', [SeccionColumnaController::class, 'update'])->name('columnas.update');
                Route::post('/{seccion}/columnas/{columna}/mover', [SeccionColumnaController::class, 'mover'])->name('columnas.mover');
                Route::delete('/{seccion}/columnas/{columna}', [SeccionColumnaController::class, 'destroy'])->name('columnas.destroy');

                Route::post('/{seccion}/filas', [SeccionFilaController::class, 'store'])->name('filas.store');
                Route::put('/{seccion}/filas/{fila}', [SeccionFilaController::class, 'update'])->name('filas.update');
                Route::delete('/{seccion}/filas/{fila}', [SeccionFilaController::class, 'destroy'])->name('filas.destroy');

                Route::delete('/{seccion}/archivos/{archivo}', [SeccionArchivoController::class, 'destroy'])->name('archivos.destroy');

                Route::post('/{seccion}/importaciones/{importacion}/calcular', [SeccionImportacionController::class, 'calcular'])->name('importaciones.calcular');
                Route::post('/{seccion}/importaciones/{importacion}/aplicar', [SeccionImportacionController::class, 'aplicar'])->name('importaciones.aplicar');
                Route::post('/{seccion}/importaciones/{importacion}/deshacer', [SeccionImportacionController::class, 'deshacer'])->name('importaciones.deshacer');
                Route::delete('/{seccion}/importaciones/{importacion}', [SeccionImportacionController::class, 'destroy'])->name('importaciones.destroy');
            });

            Route::post('/{seccion}/archivos', [SeccionArchivoController::class, 'store'])
                ->middleware('throttle:secciones-archivos')
                ->name('archivos.store');

            Route::post('/{seccion}/archivos/{archivo}/ia', [SeccionImportacionController::class, 'extraerConIa'])
                ->middleware('throttle:ia')
                ->name('archivos.ia');
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
