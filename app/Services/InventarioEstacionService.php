<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\InventarioEstacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Compara lo que una estación pide con lo que ya tiene según sus resguardos.
 * Herramienta y equipo de protección: "ya cuenta con". Consumibles: solo se
 * avisa si se le entregó lo mismo hace poco (config insumos.inventario).
 *
 * Es un aviso, no un bloqueo: la estación debe explicar en la justificación por
 * qué lo necesita y la Zona ve la marca al revisar.
 */
final class InventarioEstacionService
{
    private const MESES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

    private const PALABRAS_VACIAS = ['de', 'del', 'la', 'el', 'los', 'las', 'con', 'para', 'y', 'en', 'a', 'un', 'una', 'por', 'x', 'pieza', 'pza', 'pz', 'piezas', 'marca', 'truper', 'pretul'];

    /** Parecido mínimo (0-1) para considerar que dos descripciones son el mismo artículo. */
    private const PARECIDO_MINIMO = 0.6;

    /**
     * Descripción normalizada: sin acentos, en singular simple y sin palabras
     * vacías. Es la clave con la que se guarda y se compara el inventario.
     */
    public static function clave(string $descripcion): string
    {
        $texto = preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($descripcion))) ?? '';
        $palabras = [];

        foreach (preg_split('/\s+/', trim($texto)) ?: [] as $palabra) {
            if (strlen($palabra) > 5 && preg_match('/(or|al|on|ad|ol)es$/', $palabra) === 1) {
                $palabra = substr($palabra, 0, -2);
            } elseif (strlen($palabra) > 4 && str_ends_with($palabra, 's') && ! str_ends_with($palabra, 'ss')) {
                $palabra = substr($palabra, 0, -1);
            }

            // Letras sueltas (p/, x, a) no distinguen un artículo de otro; los números sí.
            if ($palabra !== '' && ! (strlen($palabra) === 1 && ctype_alpha($palabra)) && ! in_array($palabra, self::PALABRAS_VACIAS, true)) {
                $palabras[] = $palabra;
            }
        }

        return mb_substr(implode(' ', $palabras), 0, 190);
    }

    /**
     * Parecido (0-1) entre dos claves: 1 si tienen las mismas palabras, 0.9 si
     * una (de 2 palabras o más) está contenida en la otra y, si no, Jaccard.
     */
    public static function parecido(string $a, string $b): float
    {
        $x = array_values(array_unique(array_filter(explode(' ', $a))));
        $y = array_values(array_unique(array_filter(explode(' ', $b))));

        if ($x === [] || $y === []) {
            return 0.0;
        }

        $comunes = count(array_intersect($x, $y));
        $union = count(array_unique([...$x, ...$y]));

        if ($comunes === count($x) && $comunes === count($y)) {
            return 1.0;
        }

        if ($comunes === min(count($x), count($y)) && min(count($x), count($y)) >= 2) {
            return 0.9;
        }

        return $comunes / $union;
    }

    /**
     * Avisos para una descripción pedida por la estación.
     *
     * @return list<array{tipo: string, descripcion: string, cantidad: float, unidad: string|null, ultimo_mes: string|null, mensaje: string}>
     */
    public function avisos(Estacion|int $estacion, string $descripcion, ?Collection $inventario = null): array
    {
        $clave = self::clave($descripcion);

        if ($clave === '') {
            return [];
        }

        $inventario ??= $this->inventarioDe($estacion);
        $limite = CarbonImmutable::now()->startOfMonth()->subMonths((int) config('insumos.inventario.meses_consumibles'))->format('Y-m');
        $avisos = [];

        foreach ($inventario as $fila) {
            if (self::parecido($clave, $fila->clave) < self::PARECIDO_MINIMO) {
                continue;
            }

            if ($fila->tipo === InventarioEstacion::CONSUMIBLE) {
                if ($fila->ultimo_mes === null || $fila->ultimo_mes < $limite) {
                    continue;
                }

                $mensaje = 'Se le entregó «'.$fila->descripcion.'» en '.$this->mes($fila->ultimo_mes).'.';
                $tipo = 'ENTREGA_RECIENTE';
            } else {
                $mensaje = 'Ya cuenta con '.(float) $fila->cantidad.' '.($fila->unidad ?: 'pza').' de «'.$fila->descripcion.'» según sus resguardos'.($fila->ultimo_mes ? ' (hasta '.$this->mes($fila->ultimo_mes).')' : '').'.';
                $tipo = 'YA_TIENE';
            }

            $avisos[] = [
                'tipo' => $tipo,
                'descripcion' => $fila->descripcion,
                'cantidad' => (float) $fila->cantidad,
                'unidad' => $fila->unidad,
                'ultimo_mes' => $fila->ultimo_mes,
                'mensaje' => $mensaje,
            ];
        }

        return array_slice($avisos, 0, 3);
    }

    /**
     * Avisos de varias descripciones (una consulta de inventario).
     *
     * @param  iterable<int|string, string>  $descripciones  clave => descripción
     * @return array<int|string, list<array{tipo: string, descripcion: string, cantidad: float, unidad: string|null, ultimo_mes: string|null, mensaje: string}>>
     */
    public function avisosDe(Estacion|int $estacion, iterable $descripciones): array
    {
        $inventario = $this->inventarioDe($estacion);
        $resultado = [];

        foreach ($descripciones as $clave => $descripcion) {
            $resultado[$clave] = $this->avisos($estacion, (string) $descripcion, $inventario);
        }

        return $resultado;
    }

    /**
     * @return Collection<int, InventarioEstacion>
     */
    public function inventarioDe(Estacion|int $estacion): Collection
    {
        return InventarioEstacion::query()->where('estacion_id', $estacion instanceof Estacion ? $estacion->id : $estacion)->get();
    }

    private function mes(string $anioMes): string
    {
        [$anio, $mes] = array_pad(explode('-', $anioMes), 2, '1');

        return (self::MESES[(int) $mes] ?? $mes).' '.$anio;
    }
}
