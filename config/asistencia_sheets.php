<?php

/*
 | Escritura de la asistencia del día en el Google Sheet de Coordinación (antes la hacía n8n desde la PC).
 | Todo apagado por omisión: sin ASISTENCIA_SHEETS_ACTIVO=true el comando no escribe nada.
 */
return [
    'activo' => (bool) env('ASISTENCIA_SHEETS_ACTIVO', false),

    // Hoja de cálculo destino (el id va entre /d/ y /edit en la URL).
    'spreadsheet_id' => env('ASISTENCIA_SHEETS_ID'),

    // Pestaña fija; vacío = se llama como el mes en curso (Noviembre, Diciembre…).
    'hoja_fija' => env('ASISTENCIA_SHEETS_HOJA'),

    // JSON de la cuenta de servicio (ruta absoluta, fuera del repo, permisos 600).
    'credenciales' => env('ASISTENCIA_SHEETS_CREDENCIALES'),

    'zona_horaria' => 'America/Cancun',

    // Ventanas de captura: se escribe cuando todas las estaciones están listas; en `limite` se escribe lo que haya.
    'ventanas' => [
        'manana' => ['inicio' => '06:00', 'limite' => '10:00'],
        'tarde' => ['inicio' => '12:00', 'limite' => '16:00'],
    ],

    // Sábado y domingo no se espera su captura: todo su personal sale en DESCANSO (el nombre contiene este texto).
    'estacion_descansa_finde' => env('ASISTENCIA_SHEETS_ESTACION_DESCANSA', 'Edificio Zonal'),

    // Correo del resumen de cada corrida (el mismo que usaba n8n). Vacío = sin correo.
    'correo' => env('ASISTENCIA_SHEETS_CORREO'),
];
