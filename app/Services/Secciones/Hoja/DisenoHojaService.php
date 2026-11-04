<?php

namespace App\Services\Secciones\Hoja;

use App\Models\SeccionTabla;
use App\Support\EstiloCelda;
use Illuminate\Validation\ValidationException;

/**
 * Celdas combinadas y rangos con "formato como tabla" de una hoja
 * (seccion_tablas.diseno). Cada rango se guarda por posición: f1/c1 (arriba
 * a la izquierda) y f2/c2 (abajo a la derecha), base 0. Al insertar o borrar
 * filas/columnas se ajustan igual que las referencias de las fórmulas: un
 * rango crece si se inserta dentro y se recorta si se borra parte de él.
 */
final class DisenoHojaService
{
    public const MAX_RANGOS = 500;

    /**
     * @param  list<array<string, mixed>>  $combinaciones
     * @param  list<array<string, mixed>>  $tablas
     */
    public function guardar(SeccionTabla $tabla, array $combinaciones, array $tablas, int $totalFilas): void
    {
        $totalColumnas = $tabla->columnas()->count();

        $combinaciones = array_map(fn (array $rango): array => $this->rango($rango), $combinaciones);
        $tablas = array_map(fn (array $rango): array => $this->rango($rango) + [
            'estilo' => (string) $rango['estilo'],
            'encabezado' => (bool) ($rango['encabezado'] ?? true),
            'bandas' => (bool) ($rango['bandas'] ?? true),
        ], $tablas);

        $this->validarLimites(array_merge($combinaciones, $tablas), $totalFilas, $totalColumnas);
        $this->validarSinTraslapes($combinaciones);

        $tabla->update(['diseno' => $this->comprimir(['combinaciones' => $combinaciones, 'tablas' => $tablas])]);
    }

    /**
     * Ajusta el diseño tras insertar ($delta > 0) o borrar ($delta < 0)
     * filas o columnas desde la posición $desde. Una combinación que queda
     * de una sola celda desaparece.
     */
    public function desplazar(SeccionTabla $tabla, string $eje, int $desde, int $delta): void
    {
        $diseno = $tabla->disenoHoja();

        if ($diseno['combinaciones'] === [] && $diseno['tablas'] === []) {
            return;
        }

        $ajustar = function (array $rangos, bool $sonCombinaciones) use ($eje, $desde, $delta): array {
            $resultado = [];

            foreach ($rangos as $rango) {
                $nuevo = $this->desplazarRango($rango, $eje, $desde, $delta);

                if ($nuevo === null || ($sonCombinaciones && $nuevo['f1'] === $nuevo['f2'] && $nuevo['c1'] === $nuevo['c2'])) {
                    continue;
                }

                $resultado[] = $nuevo;
            }

            return $resultado;
        };

        $tabla->update(['diseno' => $this->comprimir([
            'combinaciones' => $ajustar($diseno['combinaciones'], true),
            'tablas' => $ajustar($diseno['tablas'], false),
        ])]);
    }

    /**
     * @param  array<string, mixed>  $rango
     * @return array<string, mixed>|null
     */
    private function desplazarRango(array $rango, string $eje, int $desde, int $delta): ?array
    {
        [$claveInicio, $claveFin] = $eje === 'fila' ? ['f1', 'f2'] : ['c1', 'c2'];
        $a = (int) $rango[$claveInicio];
        $b = (int) $rango[$claveFin];

        if ($delta > 0) {
            $a = $a >= $desde ? $a + $delta : $a;
            $b = $b >= $desde ? $b + $delta : $b;
        } else {
            $borradas = -$delta;
            $limite = $desde + $borradas;
            $a = $a < $desde ? $a : ($a >= $limite ? $a - $borradas : $desde);
            $b = $b < $desde ? $b : ($b >= $limite ? $b - $borradas : $desde - 1);

            if ($b < $a) {
                return null;
            }
        }

        return array_merge($rango, [$claveInicio => $a, $claveFin => $b]);
    }

    /**
     * @param  array<string, mixed>  $rango
     * @return array{f1: int, c1: int, f2: int, c2: int}
     */
    private function rango(array $rango): array
    {
        return [
            'f1' => min((int) $rango['f1'], (int) $rango['f2']),
            'c1' => min((int) $rango['c1'], (int) $rango['c2']),
            'f2' => max((int) $rango['f1'], (int) $rango['f2']),
            'c2' => max((int) $rango['c1'], (int) $rango['c2']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rangos
     */
    private function validarLimites(array $rangos, int $totalFilas, int $totalColumnas): void
    {
        foreach ($rangos as $rango) {
            if ($rango['f2'] >= $totalFilas || $rango['c2'] >= $totalColumnas) {
                throw ValidationException::withMessages(['diseno' => __('Un rango queda fuera de la hoja. Recarga la hoja.')]);
            }
        }
    }

    /**
     * @param  list<array{f1: int, c1: int, f2: int, c2: int}>  $combinaciones
     */
    private function validarSinTraslapes(array $combinaciones): void
    {
        foreach ($combinaciones as $i => $a) {
            if ($a['f1'] === $a['f2'] && $a['c1'] === $a['c2']) {
                throw ValidationException::withMessages(['combinaciones' => __('Para combinar elige más de una celda.')]);
            }

            foreach (array_slice($combinaciones, $i + 1) as $b) {
                if ($a['f1'] <= $b['f2'] && $b['f1'] <= $a['f2'] && $a['c1'] <= $b['c2'] && $b['c1'] <= $a['c2']) {
                    throw ValidationException::withMessages(['combinaciones' => __('Dos celdas combinadas no pueden encimarse.')]);
                }
            }
        }
    }

    /**
     * @param  array{combinaciones: list<array<string, mixed>>, tablas: list<array<string, mixed>>}  $diseno
     * @return array<string, mixed>|null
     */
    private function comprimir(array $diseno): ?array
    {
        return $diseno['combinaciones'] === [] && $diseno['tablas'] === [] ? null : $diseno;
    }

    /**
     * @return list<string>
     */
    public static function estilosTabla(): array
    {
        return array_keys(EstiloCelda::ESTILOS_TABLA);
    }
}
