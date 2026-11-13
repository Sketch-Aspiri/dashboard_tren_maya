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
    'urls' => array_filter([
        'dashboard' => rtrim((string) env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech'), '/'),
        'tickets' => rtrim((string) env('ECOSISTEMA_TICKETS_URL', 'https://tickets.zonaorientetm.tech'), '/'),
        // Portal (zonaorientetm.tech): si se define, es el origen del inicio y cierre de sesión únicos (en lugar del
        // otro sistema). Apagado por omisión: se activa cuando las cuentas ya están emparejadas con el portal.
        'portal' => env('ECOSISTEMA_PORTAL_URL') ? rtrim((string) env('ECOSISTEMA_PORTAL_URL'), '/') : null,
    ]),

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

    // Puente interno entre los dos servidores (App\Services\Ecosistema\FirmaPuente): llamadas firmadas con
    // HMAC, nunca desde el navegador. Un secreto por dirección, el MISMO valor en los dos .env (32+ caracteres):
    //   ECOSISTEMA_PUENTE_SECRETO_DASHBOARD firma lo que el Dashboard le pide a Tickets;
    //   ECOSISTEMA_PUENTE_SECRETO_TICKETS firma lo que Tickets le pide al Dashboard.
    // Sin secretos el puente queda apagado (las rutas /interno responden 404) y nada cambia.
    'puente' => [
        'secreto_entrada' => env('ECOSISTEMA_PUENTE_SECRETO_TICKETS'),
        'secreto_salida' => env('ECOSISTEMA_PUENTE_SECRETO_DASHBOARD'),
        // Dirección con la que este servidor llama a Tickets (por omisión, la pública).
        'url' => rtrim((string) (env('ECOSISTEMA_PUENTE_TICKETS_URL') ?: env('ECOSISTEMA_TICKETS_URL', 'https://tickets.zonaorientetm.tech')), '/'),
        // IPs desde las que se aceptan llamadas (separadas por coma; vacío = cualquiera, solo vale la firma).
        'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ECOSISTEMA_PUENTE_IPS', ''))))),
        // Diferencia máxima de reloj aceptada, en segundos.
        'ventana' => 300,
        'timeout' => 3,
        // Segundos que se guardan los pendientes de Tickets de cada persona.
        'cache_pendientes' => 60,
    ],

    // Herramientas para IA por el puente (App\Services\Ecosistema\IaHerramientas): el MCP de Tickets publica las consultas
    // del asistente y la captura de asistencia. APAGADO por omisión (las rutas /interno/ia responden 404): se enciende con
    // ECOSISTEMA_IA=true. Además de la firma del puente, cada cliente MCP necesita su permiso en Tickets.
    'ia' => [
        'habilitada' => (bool) env('ECOSISTEMA_IA', false),
        // "Hoy" para la captura de asistencia: la IA solo captura el día de hoy en esta zona horaria.
        'zona_horaria' => 'America/Cancun',
        // Filas máximas por llamada de captura.
        'max_registros' => 200,
        // Cuenta de sistema que figura como quien registró (sin rol ni acceso: nadie inicia sesión con ella).
        'cuenta' => [
            'correo' => 'asistente-ia@sistema.invalid',
            'nombre' => 'Asistente IA (automático)',
        ],
    ],

    // Actividades que Tickets crea por eventos del Dashboard (App\Services\Ecosistema\ActividadesEcosistema). Aquí solo se
    // eligen qué eventos se mandan; el EQUIPO que recibe cada uno se configura en Tickets (config/ecosistema.php de
    // Tickets): sin equipo allá, no se crea nada. Necesitan el puente.
    'actividades' => [
        'eventos' => array_values(array_filter(array_map('trim', explode(',', (string) env('ECOSISTEMA_ACT_EVENTOS', 'insumos_observada,insumos_devuelta,factura_cancelada,asistencia_pendiente'))))),
        // Hora límite (America/Cancun, lunes a viernes) para abrir la actividad de las estaciones sin asistencia.
        'asistencia_hora' => env('ECOSISTEMA_ACT_ASISTENCIA_HORA', '12:00'),
    ],

    // Avisos al celular por la PWA de Tickets (App\Services\Ecosistema\AvisosEcosistema). Necesitan el puente.
    'avisos' => [
        // Hora (America/Cancun, lunes a viernes) del recordatorio a las estaciones sin asistencia capturada.
        'asistencia_hora' => env('ECOSISTEMA_AVISO_ASISTENCIA_HORA', '10:00'),
    ],

];
