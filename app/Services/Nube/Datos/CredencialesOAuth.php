<?php

namespace App\Services\Nube\Datos;

/**
 * Credenciales resueltas de una app OAuth. El secreto no sale en volcados
 * (var_dump, logs de excepción) gracias a __debugInfo.
 */
final readonly class CredencialesOAuth
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $tenant,
        public string $origen,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => '[oculto]', 'tenant' => $this->tenant, 'origen' => $this->origen];
    }
}
