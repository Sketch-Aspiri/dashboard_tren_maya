<?php

namespace App\Console\Commands;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\InventarioEstacion;
use App\Models\Proveedor;
use App\Services\InventarioEstacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga (idempotente) los datos del fondo rotatorio sacados de los resguardos y
 * de las constancias fiscales: inventario por estación, partidas faltantes del
 * catálogo y lista de proveedores. Los archivos están en database/data. Por
 * omisión solo aplica partidas de confianza ALTA o MEDIA; las dudosas se dejan
 * sin partida para que la jefatura las asigne.
 */
class CargarDatosInsumosCommand extends Command
{
    protected $signature = 'insumos:cargar-datos
        {--solo= : inventario, partidas o proveedores (por omisión, los tres)}
        {--incluir-dudosas : aplica también las partidas de confianza BAJA}
        {--simular : cuenta lo que haría sin guardar nada}';

    protected $description = 'Carga inventario de estaciones, partidas faltantes del catálogo y proveedores';

    public function handle(): int
    {
        $solo = (string) $this->option('solo');
        $simular = (bool) $this->option('simular');

        DB::beginTransaction();

        try {
            if (in_array($solo, ['', 'proveedores'], true)) {
                $this->proveedores();
            }

            if (in_array($solo, ['', 'partidas'], true)) {
                $this->partidasDelCatalogo();
            }

            if (in_array($solo, ['', 'inventario'], true)) {
                $this->inventario();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $simular ? DB::rollBack() : DB::commit();
        $this->info($simular ? 'Simulación terminada: no se guardó nada.' : 'Carga terminada.');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leer(string $archivo): array
    {
        return json_decode((string) file_get_contents(database_path('data/'.$archivo)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function aceptada(string $confianza): bool
    {
        return in_array($confianza, ['ALTA', 'MEDIA'], true) || ($confianza === 'BAJA' && $this->option('incluir-dudosas'));
    }

    private function proveedores(): void
    {
        $nuevos = $actualizados = 0;

        foreach ($this->leer('proveedores_fondo.json') as $dato) {
            $proveedor = Proveedor::query()->where('rfc', $dato['rfc'])->first() ?? Proveedor::query()->where('nombre', $dato['nombre'])->first();

            if ($proveedor === null) {
                Proveedor::query()->create(['nombre' => $dato['nombre'], 'rfc' => $dato['rfc'], 'giro' => $dato['giro'], 'ubicacion' => $dato['ubicacion']]);
                $nuevos++;

                continue;
            }

            $proveedor->update(['rfc' => $proveedor->rfc ?: $dato['rfc'], 'giro' => $proveedor->giro ?: $dato['giro'], 'ubicacion' => $proveedor->ubicacion ?: $dato['ubicacion']]);
            $actualizados++;
        }

        $this->line("Proveedores: {$nuevos} nuevos, {$actualizados} completados (el IVA no se toca).");
    }

    private function partidasDelCatalogo(): void
    {
        $aplicadas = $omitidas = 0;

        foreach ($this->leer('catalogo_partidas.json') as $dato) {
            if (! $this->aceptada($dato['confianza']) || blank($dato['partida'])) {
                $omitidas++;

                continue;
            }

            $aplicadas += CatalogoInsumo::query()->where('descripcion', $dato['descripcion'])
                ->where(fn ($q) => $q->whereNull('partida_presupuestal')->orWhere('partida_presupuestal', ''))
                ->update(['partida_presupuestal' => $dato['partida']]);
        }

        $this->line("Catálogo: {$aplicadas} partidas asignadas, {$omitidas} dudosas sin asignar.");
    }

    private function inventario(): void
    {
        $estaciones = Estacion::query()->pluck('id', 'nombre');
        $grupos = [];
        $sinEstacion = 0;

        foreach ($this->leer('inventario_estaciones.json') as $dato) {
            $estacionId = $estaciones[$dato['estacion']] ?? null;

            if ($estacionId === null) {
                $sinEstacion++;

                continue;
            }

            $clave = InventarioEstacionService::clave($dato['descripcion']);

            if ($clave === '') {
                continue;
            }

            $llave = $estacionId.'|'.$dato['tipo'].'|'.$clave;
            $grupo = $grupos[$llave] ??= [
                'estacion_id' => $estacionId, 'tipo' => $dato['tipo'], 'clave' => $clave, 'descripcion' => $dato['descripcion'], 'unidad' => $dato['unidad'] ?: null,
                'cantidad' => 0.0, 'primer_mes' => $dato['primer_mes'], 'ultimo_mes' => $dato['ultimo_mes'], 'partida_presupuestal' => null,
            ];

            $grupos[$llave]['cantidad'] = $grupo['cantidad'] + (float) $dato['cantidad'];
            $grupos[$llave]['primer_mes'] = $this->menor($grupo['primer_mes'], $dato['primer_mes']);
            $grupos[$llave]['ultimo_mes'] = $this->mayor($grupo['ultimo_mes'], $dato['ultimo_mes']);

            if ($grupo['partida_presupuestal'] === null && $this->aceptada($dato['confianza']) && filled($dato['partida'])) {
                $grupos[$llave]['partida_presupuestal'] = $dato['partida'];
            }
        }

        foreach ($grupos as $grupo) {
            InventarioEstacion::query()->updateOrCreate(
                ['estacion_id' => $grupo['estacion_id'], 'tipo' => $grupo['tipo'], 'clave' => $grupo['clave']],
                $grupo + ['fuente' => 'resguardos'],
            );
        }

        $this->line('Inventario: '.count($grupos).' renglones por estación'.($sinEstacion > 0 ? " ({$sinEstacion} omitidos por estación desconocida)" : '').'.');
    }

    private function menor(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private function mayor(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }
}
