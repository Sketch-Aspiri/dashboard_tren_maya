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

// Escribe en el Google Sheet de Coordinación la asistencia capturada (antes lo hacía n8n desde la PC): cada 10 min en las ventanas
// de captura 06-10 y 12-16 y, a las 10:00 y 16:00, lo que haya aunque falten estaciones. Apagado hasta ASISTENCIA_SHEETS_ACTIVO=true.
Schedule::command('asistencia:sincronizar-sheets')
    ->cron('*/10 6-9,12-15 * * *')
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('asistencia:sincronizar-sheets --limite')
    ->cron('0 10,16 * * *')
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();

// A la hora límite, una actividad en Tickets por cada estación sin asistencia capturada (una vez por estación y día).
// Sin el puente configurado, o con el evento apagado (ECOSISTEMA_ACT_EVENTOS), no hace nada.
Schedule::command('asistencia:abrir-actividades')
    ->weekdays()
    ->dailyAt((string) config('ecosistema.actividades.asistencia_hora', '12:00'))
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();

// Recordatorio al celular (PWA de Tickets) de las estaciones que aún no capturan la asistencia del día.
// Una vez por estación y día; sin el puente configurado no hace nada.
Schedule::command('asistencia:recordar-pendientes')
    ->weekdays()
    ->dailyAt((string) config('ecosistema.avisos.asistencia_hora', '10:00'))
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();

// Cada lunes deja listo el consolidado de horarios de la semana para enviarlo a Coordinación (se descarga en /horarios/consolidados).
Schedule::command('horarios:consolidado-semanal')
    ->weeklyOn(1, (string) config('horarios.consolidado_hora', '07:00'))
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();

// Avisa a todas las cuentas quién cumple años hoy (un solo aviso al día; ninguno si nadie cumple).
Schedule::command('cumpleanos:avisar-hoy')
    ->dailyAt((string) config('ecosistema.avisos.cumpleanos_hora', '08:00'))
    ->timezone('America/Cancun')
    ->withoutOverlapping()
    ->onOneServer();

// Limpia los trabajos fallidos con más de 7 días: los de avisos y actividades al ecosistema guardan correos y textos.
Schedule::command('queue:prune-failed --hours=168')->daily()->onOneServer();
