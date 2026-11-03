<?php

namespace App\Support;

use App\Models\Estacion;

/**
 * Traduce el texto de una celda ("Estación Chetumal", "Tulum-Aeropuerto",
 * "BACALAR (Q. Roo)") a una estación del catálogo. Coincidencia EXACTA tras
 * normalizar: nunca por subcadena, para que "Tulum" no caiga en "Tulum
 * Aeropuerto". Lo que no coincide devuelve null (la fila queda sin etiqueta).
 */
final class ResolutorEstacion
{
    /** @var array<string, int>|null nombre normalizado => id */
    private ?array $indice = null;

    public function resolver(mixed $valor): ?int
    {
        $clave = self::normalizar($valor);

        return $clave === null ? null : ($this->indice()[$clave] ?? null);
    }

    public static function normalizar(mixed $valor): ?string
    {
        $texto = Texto::normalizar($valor);
        $texto = (string) preg_replace('/[\-–—_]/u', ' ', $texto);
        $texto = (string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $texto);
        $texto = (string) preg_replace('/^estacion\s+/u', '', trim($texto));
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return $texto === '' ? null : $texto;
    }

    /**
     * @return array<string, int>
     */
    private function indice(): array
    {
        if ($this->indice === null) {
            $this->indice = [];

            foreach (Estacion::query()->get(['id', 'nombre']) as $estacion) {
                $clave = self::normalizar($estacion->nombre);

                if ($clave !== null) {
                    $this->indice[$clave] = $estacion->id;
                }
            }
        }

        return $this->indice;
    }
}
