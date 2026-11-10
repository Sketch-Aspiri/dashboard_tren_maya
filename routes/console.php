<?php

use App\Console\Commands\NubeDiagnosticoCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Orígenes de nube (carpetas y archivos vinculados desde la nube): cada
// SECCIONES_SYNC_MINUTOS (15 por defecto) se encola un SincronizarOrigenJob por
// origen activo. REQUIERE las dos piezas del servidor: el cron único de
// `schedule:run` (deploy/vps/cron.txt) Y el worker de cola con la cola `nube`
// (deploy/vps/supervisor-dashboard.conf); sin el worker los jobs se acumulan.
// Latido del scheduler: `php artisan nube:diagnostico` lo lee para saber si el cron del servidor corre.
Schedule::call(fn () => Cache::put(NubeDiagnosticoCommand::CLAVE_CRON, now()->timestamp, now()->addDay()))
    ->name('nube-latido')
    ->everyMinute();

Schedule::command('secciones:sincronizar-nube')
    ->cron('*/'.max(1, min(59, (int) config('secciones.nube.minutos'))).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

// Facturas del fondo rotatorio: cada noche se vuelve a preguntar al SAT por las vigentes (detecta cancelaciones) y por las que no se pudieron consultar.
Schedule::command('facturas:verificar-sat')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
