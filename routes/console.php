<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Documentos vinculados a la nube (Drive, OneDrive, Dropbox): se vuelven a
// descargar y, si cambiaron, se actualizan archivo y tablas. Requiere el cron
// único de `schedule:run` en el servidor.
Schedule::command('secciones:sincronizar-nube')
    ->cron('*/'.max(1, min(59, (int) config('secciones.nube.minutos'))).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();
