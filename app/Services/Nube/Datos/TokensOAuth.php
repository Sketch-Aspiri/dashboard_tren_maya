<?php

namespace App\Services\Nube\Datos;

use Carbon\CarbonImmutable;

/**
 * Tokens recién obtenidos del proveedor. No sale en volcados (__debugInfo).
 */
final readonly class TokensOAuth
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $expiraEn,
        public ?string $scopes,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[oculto]', 'refreshToken' => '[oculto]', 'scopes' => (string) $this->scopes];
    }
}
