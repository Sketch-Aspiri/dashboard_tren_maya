<?php

/*
 * Ecosistema Zona Oriente: el Dashboard y el sistema de Tickets se usan como
 * uno solo (selector de sistema, tema compartido y, en fases siguientes,
 * inicio de sesión único y datos compartidos). Ver docs/ECOSISTEMA.md.
 */
return [

    // Este sistema dentro del ecosistema.
    'sistema' => 'dashboard',

    // Dirección pública de cada sistema (sin diagonal final).
    'urls' => [
        'dashboard' => rtrim((string) env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech'), '/'),
        'tickets' => rtrim((string) env('ECOSISTEMA_TICKETS_URL', 'https://tickets.zonaorientetm.tech'), '/'),
    ],

    // Inicio de sesión único entre los dos sistemas (App\Services\Ecosistema\InicioUnicoService).
    // ECOSISTEMA_SSO_SECRET: el MISMO valor en los .env de ambos sistemas (32+ caracteres aleatorios;
    // `php -r "echo bin2hex(random_bytes(32));"`). Sin él, cada sistema pide su propio login.
    'sso' => [
        'secreto' => env('ECOSISTEMA_SSO_SECRET'),
        // Vigencia de cada código firmado, en segundos.
        'vigencia' => 60,
        // Tras preguntarle al otro sistema, no se vuelve a preguntar en estos minutos (evita ciclos).
        'reintento_minutos' => 5,
    ],

];
