<?php

namespace App\Services;

use App\Models\CatalogoInsumo;
use App\Models\Proveedor;
use App\Models\SolicitudInsumoPartida;
use Illuminate\Support\Collection;

/**
 * Proveedores del fondo rotatorio y su IVA. Las partidas guardan el nombre
 * del proveedor como texto; aquí se relaciona por nombre (sin distinguir
 * mayúsculas ni espacios sobrantes) para calcular el IVA de cada proveedor
 * por separado, porque unos facturan al 8 % y otros al 16 %.
 */
final class ProveedorService
{
    /**
     * Clave de agrupación de un nombre de proveedor ('' = sin proveedor).
     */
    public static function clave(?string $nombre): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $nombre)));
    }

    /**
     * Tasa (%) configurada por clave de proveedor; null = sin definir.
     *
     * @return array<string, int|null>
     */
    public function tasas(): array
    {
        return Proveedor::query()->get(['nombre', 'iva'])
            ->mapWithKeys(fn (Proveedor $p) => [self::clave($p->nombre) => $p->iva])
            ->all();
    }

    /**
     * Tasa (%) que se aplica a un proveedor: la configurada o, si no está
     * definida (o no tiene proveedor), la tasa por defecto.
     *
     * @param  array<string, int|null>|null  $tasas  Salida de tasas() ya cargada.
     */
    public function tasaAplicable(?string $nombre, ?array $tasas = null): int
    {
        $tasas ??= $this->tasas();

        return $tasas[self::clave($nombre)] ?? (int) config('insumos.iva_defecto');
    }

    /**
     * IVA separado por proveedor: una fila por proveedor (los sin proveedor
     * juntos), con su base, su tasa y el IVA que le corresponde.
     *
     * @param  Collection<int, SolicitudInsumoPartida>  $partidas
     * @return list<array{clave: string, proveedor: string|null, tasa: int, configurada: bool, base: float, iva: float}>
     */
    public function desglose(Collection $partidas): array
    {
        $tasas = $this->tasas();
        $grupos = [];

        foreach ($partidas as $partida) {
            $clave = self::clave($partida->proveedor);
            $grupos[$clave]['proveedor'] ??= trim((string) $partida->proveedor) === '' ? null : trim((string) $partida->proveedor);
            $grupos[$clave]['base'] = ($grupos[$clave]['base'] ?? 0.0) + ($partida->total() ?? 0.0);
        }

        $filas = [];

        foreach ($grupos as $clave => $grupo) {
            $base = round($grupo['base'], 2);
            $tasa = $this->tasaAplicable($grupo['proveedor'], $tasas);

            $filas[] = [
                'clave' => (string) $clave,
                'proveedor' => $grupo['proveedor'],
                'tasa' => $tasa,
                'configurada' => ($tasas[$clave] ?? null) !== null,
                'base' => $base,
                'iva' => round($base * $tasa / 100, 2),
            ];
        }

        return $filas;
    }

    /**
     * Proveedor al que corresponde un emisor de factura: primero por RFC y,
     * si no, por nombre exacto o porque uno contiene al otro ("Continental"
     * dentro de "GRUPO FERRETERO CONTINENTAL").
     */
    public function buscar(?string $rfc, ?string $nombre): ?Proveedor
    {
        $rfc = strtoupper(trim((string) $rfc));

        if ($rfc !== '' && ($porRfc = Proveedor::query()->where('rfc', $rfc)->first()) !== null) {
            return $porRfc;
        }

        $buscado = self::clave($nombre);

        if ($buscado === '') {
            return null;
        }

        foreach (Proveedor::query()->get() as $proveedor) {
            $clave = self::clave($proveedor->nombre);

            if ($clave === $buscado || (mb_strlen($clave) >= 4 && (str_contains($buscado, $clave) || str_contains($clave, $buscado)))) {
                return $proveedor;
            }
        }

        return null;
    }

    /**
     * Da de alta un proveedor visto en una partida o en el catálogo, sin
     * IVA definido, para que la administración lo configure.
     */
    public function registrar(?string $nombre): void
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));

        if ($nombre === '' || mb_strlen($nombre) > 150) {
            return;
        }

        Proveedor::query()->firstOrCreate(['nombre' => $nombre], ['iva' => null]);
    }

    /**
     * Registra los proveedores que ya aparecen en el catálogo y en las
     * partidas pero todavía no están en la tabla. Devuelve cuántos agregó.
     */
    public function sincronizar(): int
    {
        $antes = Proveedor::query()->count();

        $nombres = CatalogoInsumo::query()->whereNotNull('proveedor')->distinct()->pluck('proveedor')
            ->merge(SolicitudInsumoPartida::query()->whereNotNull('proveedor')->distinct()->pluck('proveedor'));

        foreach ($nombres as $nombre) {
            $this->registrar($nombre);
        }

        return Proveedor::query()->count() - $antes;
    }
}
