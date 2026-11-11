<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Fija los proxies de confianza a partir de TRUSTED_PROXIES (config/proxies.php): vacío = ninguno, `*` = todos
 * (solo para desarrollo detrás de un túnel) o una lista de IP/CIDR separada por comas.
 */
final class ProxyTrust
{
    public static function configure(?string $trusted): void
    {
        $trusted = trim((string) $trusted);

        if ($trusted === '') {
            TrustProxies::flushState();

            return;
        }

        TrustProxies::at($trusted === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trusted)))));
    }
}
