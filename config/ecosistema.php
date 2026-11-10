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

];
