<?php

/*
 * Proxies de confianza para leer la IP del cliente y el esquema (X-Forwarded-*).
 *
 * TRUSTED_PROXIES: vacío = no se confía en ninguno (lo correcto en el VPS: nginx ya entrega la IP real con
 * `real_ip_header` y `fastcgi_param HTTPS on`). Con `*` cualquiera podría falsear su IP con una cabecera
 * X-Forwarded-For y esquivar los límites de intentos. Solo hace falta en desarrollo detrás de un túnel (p. ej.
 * Cloudflare): ahí `TRUSTED_PROXIES=*` o la IP del túnel, separadas por comas.
 */
return [
    'trusted' => env('TRUSTED_PROXIES'),
];
