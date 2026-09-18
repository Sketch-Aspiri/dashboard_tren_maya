<?php

namespace App\Console\Commands\Concerns;

use App\Models\Estacion;

/**
 * Matches free-text station names from the Jefe de Zona's spreadsheets
 * against the `estaciones` catalog (app:import-estadisticas and
 * app:import-rol-vacaciones). Matching is a normalized substring test,
 * longest catalog name first, so "Tulum Aeropuerto" is never claimed by the
 * shorter "Tulum".
 */
trait ResolvesEstacionesPorNombre
{
    /**
     * @param  array<string, Estacion>  $estacionesPorNombre  from estacionesPorNombreNormalizadoDescendente()
     * @param  array<string, string>  $alias  normalized source substring => catalog `nombre`, for names
     *                                        the catalog spells differently (the catalog is never renamed)
     */
    private function resolverEstacion(string $normalizedHeader, array $estacionesPorNombre, array $alias = []): ?Estacion
    {
        foreach ($alias as $aliasClave => $nombreEstacion) {
            if (str_contains($normalizedHeader, $aliasClave)) {
                return $estacionesPorNombre[$this->normalizeHeader($nombreEstacion)] ?? null;
            }
        }

        foreach ($estacionesPorNombre as $nombreNormalizado => $estacion) {
            if (str_contains($normalizedHeader, $nombreNormalizado)) {
                return $estacion;
            }
        }

        return null;
    }

    /**
     * @return array<string, Estacion>
     */
    private function estacionesPorNombreNormalizadoDescendente(): array
    {
        $indexed = [];

        foreach (Estacion::query()->get() as $estacion) {
            $nombreNormalizado = $this->normalizeHeader($estacion->nombre);

            if ($nombreNormalizado !== null) {
                $indexed[$nombreNormalizado] = $estacion;
            }
        }

        uksort($indexed, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $indexed;
    }

    /**
     * Uppercase + strip accents + collapse whitespace, so matching is
     * independent of accents/casing in the source file.
     */
    private function normalizeHeader(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(mb_strtoupper($value));
        $normalized = strtr($normalized, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }
}
