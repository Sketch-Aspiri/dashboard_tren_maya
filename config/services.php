<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Claude API (asistente del dashboard y "Extraer con IA"). Sin llave, la
     * IA queda apagada en toda la aplicación (App\Services\Asistente\ClienteClaude).
     */
    'anthropic' => [
        'key' => (string) env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 120),
        'asistente_por_minuto' => (int) env('ASISTENTE_LIMITE_POR_MINUTO', 10),
        'asistente_por_dia' => (int) env('ASISTENTE_LIMITE_DIARIO', 300),
    ],

    /*
     * OAuth de las nubes (solo lectura). Las apps las registra TI; ver
     * docs/nube-oauth.md. Se usan desde la fase F2 de
     * docs/plan-sincronizacion-nube.md. Siempre leer con config(), nunca env().
     */
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'dropbox' => [
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
        'redirect' => env('DROPBOX_REDIRECT_URI'),
    ],

    // Sistema de Tickets (otro sitio en la misma VPS): Temayin enlaza ahí para reportar fallas a Soporte.
    'tickets' => [
        'soporte_url' => env('TICKETS_SOPORTE_URL', 'https://tickets.zonaorientetm.tech/tickets/create'),
    ],

];
