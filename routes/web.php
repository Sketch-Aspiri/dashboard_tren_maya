<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExampleController;
use App\Http\Controllers\ProfileController;
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

    Route::prefix('examples')->name('examples.')->group(function () {
        Route::get('/', [ExampleController::class, 'index'])->name('index');
        Route::get('/data', [ExampleController::class, 'data'])
            ->middleware('throttle:examples-data')
            ->name('data');
        Route::get('/create', [ExampleController::class, 'create'])->name('create');
        Route::post('/', [ExampleController::class, 'store'])->name('store');
        Route::get('/{example}', [ExampleController::class, 'show'])->name('show');
        Route::get('/{example}/edit', [ExampleController::class, 'edit'])->name('edit');
        Route::put('/{example}', [ExampleController::class, 'update'])->name('update');
        Route::delete('/{example}', [ExampleController::class, 'destroy'])->name('destroy');
    });
});

require __DIR__.'/auth.php';
