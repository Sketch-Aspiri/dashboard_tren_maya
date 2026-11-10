<?php

namespace App\Services;

/**
 * Denominación oficial de cada partida presupuestal (clave => nombre), leída
 * de resources/documentos/partidas_denominacion.json.
 */
final class DenominacionPartidas
{
    /** @var array<string, string> */
    private array $mapa = [];

    /**
     * @return array<string, string>
     */
    public function todas(): array
    {
        if ($this->mapa === []) {
            $this->mapa = json_decode((string) file_get_contents(resource_path('documentos/partidas_denominacion.json')), true) ?: [];
        }

        return $this->mapa;
    }

    public function de(string $codigo): string
    {
        return $this->todas()[$codigo] ?? 'sin denominación';
    }
}
